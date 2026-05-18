<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\Data\AiImage;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;

/**
 * Base adapter for providers that speak the OpenAI /v1/chat/completions
 * dialect: OpenAI, xAI (Grok), Mistral and DeepSeek.
 */
class OpenAiCompatibleAdapter extends AbstractAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com';
    }

    protected function providerLabel(): string
    {
        return $this->model->provider?->name ?? 'OpenAI-compatible';
    }

    public function sendMessage(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $chatMessages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($this->normalizeMessages($messages) as $message) {
            $chatMessages[] = $this->formatMessage($message);
        }

        $payload = [
            'model' => $this->modelString(),
            'messages' => $chatMessages,
        ];

        // OpenAI o-series reasoning models require max_completion_tokens and reject temperature.
        if (preg_match('/^o\d/', $this->modelString())) {
            $payload['max_completion_tokens'] = (int) ($options['max_tokens'] ?? 1500);
        } else {
            $payload['max_tokens'] = (int) ($options['max_tokens'] ?? 1500);

            if (isset($options['temperature'])) {
                $payload['temperature'] = (float) $options['temperature'];
            }
        }

        $startedAt = microtime(true);

        $response = Http::withToken($this->apiKey())
            ->timeout(180)
            ->post($this->baseUrl().'/v1/chat/completions', $payload);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new AiProviderException(
                $this->providerLabel().' API greška ('.$response->status().'): '.$response->body()
            );
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];

        return new AiResponse(
            content: trim((string) ($choice['message']['content'] ?? '')),
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
            model: $data['model'] ?? $this->modelString(),
            finishReason: $choice['finish_reason'] ?? 'stop',
            responseTimeMs: $elapsedMs,
            raw: is_array($data) ? $data : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMessage(AiMessage $message): array
    {
        if (! $message->hasImages() || ! $this->supportsVision()) {
            return ['role' => $message->role, 'content' => $message->content];
        }

        $parts = [['type' => 'text', 'text' => $message->content]];
        foreach ($message->images as $image) {
            /** @var AiImage $image */
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $image->dataUri()],
            ];
        }

        return ['role' => $message->role, 'content' => $parts];
    }
}
