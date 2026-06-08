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
        $payload = [
            'model' => $this->modelString(),
            'max_tokens' => (int) ($options['max_tokens'] ?? 1500),
            'system' => $systemPrompt,
            'messages' => $this->buildMessages($this->normalizeMessages($messages)),
        ];

        // Opus 4.7+ rejects the `temperature` parameter.
        $rejectsTemperature = preg_match('/^claude-opus-4-[7-9]/', $this->modelString());
        if (isset($options['temperature']) && ! $rejectsTemperature) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $startedAt = microtime(true);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(180)->post($this->baseUrl().'/v1/messages', $payload);

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

        return new AiResponse(
            content: trim($text),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            model: $data['model'] ?? $this->modelString(),
            finishReason: $data['stop_reason'] ?? 'stop',
            responseTimeMs: $elapsedMs,
            raw: is_array($data) ? $data : [],
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
