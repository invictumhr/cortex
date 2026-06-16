<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\Data\AiImage;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;

class AnthropicAdapter extends AbstractAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    public function sendMessage(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        // Prompt caching: the system block (persona prompt + boardroom rules +
        // pinned context/constraints) is identical for the same persona across
        // rounds, so an ephemeral cache breakpoint turns every round after the
        // first into a 90%-discounted cache read. Below Anthropic's minimum
        // cacheable size (1024/2048 tokens) the marker is silently ignored —
        // no penalty either way.
        $system = config('cortex.prompt_caching', true)
            ? [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]]
            : $systemPrompt;

        $payload = [
            'model' => $this->modelString(),
            'max_tokens' => (int) ($options['max_tokens'] ?? 1500),
            'system' => $system,
            'messages' => $this->buildMessages($this->normalizeMessages($messages)),
        ];

        // Opus 4.7+ rejects the `temperature` parameter.
        $rejectsTemperature = preg_match('/^claude-opus-4-[7-9]/', $this->modelString());
        if (isset($options['temperature']) && ! $rejectsTemperature) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $startedAt = microtime(true);

        $response = $this->withRetries(Http::withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(180))->post($this->baseUrl().'/v1/messages', $payload);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new AiProviderException(
                'Anthropic API greška ('.$response->status().'): '.$response->body()
            );
        }

        $data = $response->json();

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        // Anthropic's usage.input_tokens EXCLUDES cached tokens. Cache writes
        // bill at 1.25× the input rate, cache reads at 0.1× — fold both into
        // one input-equivalent so calculateCost() charges what Anthropic does.
        $uncachedInput = (int) ($data['usage']['input_tokens'] ?? 0);
        $cacheCreation = (int) ($data['usage']['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($data['usage']['cache_read_input_tokens'] ?? 0);

        return new AiResponse(
            content: trim($text),
            inputTokens: $uncachedInput + $cacheCreation + $cacheRead,
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            model: $data['model'] ?? $this->modelString(),
            finishReason: $this->normalizeFinishReason($data['stop_reason'] ?? 'stop'),
            responseTimeMs: $elapsedMs,
            raw: is_array($data) ? $data : [],
            cacheCreationInputTokens: $cacheCreation,
            cacheReadInputTokens: $cacheRead,
            billableInputTokens: (int) ceil($uncachedInput + 1.25 * $cacheCreation + 0.1 * $cacheRead),
        );
    }

    /**
     * @param  AiMessage[]  $messages
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(array $messages): array
    {
        return array_map(function (AiMessage $message) {
            if (! $message->hasImages()) {
                return ['role' => $message->role, 'content' => $message->content];
            }

            $content = [];
            foreach ($message->images as $image) {
                /** @var AiImage $image */
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image->mimeType,
                        'data' => $image->base64Data,
                    ],
                ];
            }
            $content[] = ['type' => 'text', 'text' => $message->content];

            return ['role' => $message->role, 'content' => $content];
        }, $messages);
    }
}
