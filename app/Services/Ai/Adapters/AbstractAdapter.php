<?php

namespace App\Services\Ai\Adapters;

use App\Models\AiModel;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiProviderException;

abstract class AbstractAdapter implements AiProviderInterface
{
    public function __construct(protected AiModel $model) {}

    public function supportsVision(): bool
    {
        return (bool) $this->model->supports_vision;
    }

    public function supportsTools(): bool
    {
        return (bool) $this->model->supports_tools;
    }

    public function getMaxTokens(): int
    {
        return (int) $this->model->max_context_tokens;
    }

    public function calculateCost(int $inputTokens, int $outputTokens): float
    {
        return $this->model->calculateCost($inputTokens, $outputTokens);
    }

    protected function modelString(): string
    {
        return $this->model->model_string;
    }

    protected function apiKey(): string
    {
        $key = $this->model->provider?->api_key;

        if (blank($key)) {
            throw new AiProviderException(
                'Nedostaje API ključ za providera: '.($this->model->provider?->name ?? 'nepoznat')
            );
        }

        return $key;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->model->provider?->api_base_url ?: $this->defaultBaseUrl(), '/');
    }

    abstract protected function defaultBaseUrl(): string;

    /**
     * Merge consecutive same-role messages so providers that require strict
     * user/assistant alternation (Anthropic, Gemini) never receive two in a row.
     *
     * @param  AiMessage[]  $messages
     * @return AiMessage[]
     */
    protected function normalizeMessages(array $messages): array
    {
        /** @var AiMessage[] $normalized */
        $normalized = [];

        foreach ($messages as $message) {
            $last = end($normalized) ?: null;

            if ($last instanceof AiMessage
                && $last->role === $message->role
                && ! $message->hasImages()
                && ! $last->hasImages()
            ) {
                $last->content .= "\n\n".$message->content;

                continue;
            }

            $normalized[] = new AiMessage($message->role, $message->content, $message->images);
        }

        // The first message must come from the user for every provider.
        if ($normalized !== [] && $normalized[0]->role !== AiMessage::ROLE_USER) {
            array_unshift($normalized, AiMessage::user('(nastavak razgovora)'));
        }

        return $normalized;
    }
}
