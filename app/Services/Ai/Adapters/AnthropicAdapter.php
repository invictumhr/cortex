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

        // Opus 4.7+ and the whole Claude 5 generation (Opus 5, Sonnet 5,
        // Fable/Mythos 5) reject the `temperature` parameter with a 400.
        if (isset($options['temperature']) && ! $this->rejectsTemperature()) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        // Claude 5 Opus/Sonnet run adaptive thinking BY DEFAULT, and thinking
        // tokens bill as output while eating the max_tokens budget — a 1200
        // token boardroom turn would come back mostly-thinking and truncated.
        // Explicitly disable it (accepted at the default effort level) so the
        // whole budget goes to the visible contribution, matching how the 4.x
        // personas behave. Fable/Mythos 5 reject `disabled`, so they are
        // deliberately excluded — don't route boardroom personas there without
        // revisiting this.
        if (preg_match('/^claude-(opus|sonnet)-[5-9]/', $this->modelString())) {
            $payload['thinking'] = ['type' => 'disabled'];
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
     * Models that 400 on the `temperature` parameter: Opus 4.7/4.8/4.9 and
     * every Claude 5+ model (opus-5, sonnet-5, fable-5, mythos-5, …).
     */
    private function rejectsTemperature(): bool
    {
        return (bool) preg_match(
            '/^claude-(opus-4-[7-9]|opus-[5-9]|sonnet-[5-9]|fable-|mythos-)/',
            $this->modelString(),
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
