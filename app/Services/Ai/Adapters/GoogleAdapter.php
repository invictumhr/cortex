<?php

namespace App\Services\Ai\Adapters;

use App\Services\Ai\Data\AiImage;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;

class GoogleAdapter extends AbstractAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com';
    }

    public function sendMessage(string $systemPrompt, array $messages, array $options = []): AiResponse
    {
        $contents = [];
        foreach ($this->normalizeMessages($messages) as $message) {
            $contents[] = $this->formatMessage($message);
        }

        $generationConfig = [
            'maxOutputTokens' => (int) ($options['max_tokens'] ?? 1500),
        ];

        // Gemini 2.5 Flash spends the output budget on internal "thinking",
        // which truncates the visible answer; disable it so the whole budget
        // goes to the reply.
        if (str_contains($this->modelString(), 'flash')) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => $generationConfig,
        ];

        $url = $this->baseUrl().'/v1beta/models/'.$this->modelString().':generateContent';

        $startedAt = microtime(true);

        $response = $this->withRetries(Http::withHeaders([
            'content-type' => 'application/json',
            'x-goog-api-key' => $this->apiKey(),
        ])->timeout(180))->post($url, $payload);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new AiProviderException(
                'Google Gemini API greška ('.$response->status().'): '.$response->body()
            );
        }

        $data = $response->json();
        $candidate = $data['candidates'][0] ?? [];

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        return new AiResponse(
            content: trim($text),
            inputTokens: (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            model: $this->modelString(),
            finishReason: $this->normalizeFinishReason($candidate['finishReason'] ?? 'stop'),
            responseTimeMs: $elapsedMs,
            raw: is_array($data) ? $data : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessage(AiMessage $message): array
    {
        $role = $message->role === AiMessage::ROLE_ASSISTANT ? 'model' : 'user';

        $parts = [];
        if ($message->hasImages() && $this->supportsVision()) {
            foreach ($message->images as $image) {
                /** @var AiImage $image */
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $image->mimeType,
                        'data' => $image->base64Data,
                    ],
                ];
            }
        }
        $parts[] = ['text' => $message->content];

        return ['role' => $role, 'parts' => $parts];
    }
}
