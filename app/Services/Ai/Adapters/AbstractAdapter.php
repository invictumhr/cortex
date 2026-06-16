<?php

namespace App\Services\Ai\Adapters;

use App\Models\AiModel;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

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
     * Retry transient provider failures on the same model before the caller's
     * fallback-model logic kicks in. Retries fire on connection errors and on
     * 408/429/5xx responses — never on 4xx request errors, which would just
     * repeat the same mistake. `throw: false` hands the final failed response
     * back to the adapter so its existing `$response->failed()` branch keeps
     * producing the provider-specific exception message.
     */
    protected function withRetries(PendingRequest $request): PendingRequest
    {
        $backoff = array_values(array_map(
            'intval',
            (array) config('cortex.provider_retry_backoff_ms', [1000, 4000]),
        ));

        if ($backoff === []) {
            return $request;
        }

        return $request->retry(
            $backoff,
            when: function (\Throwable $e): bool {
                if ($e instanceof ConnectionException) {
                    return true;
                }

                if ($e instanceof RequestException) {
                    $status = $e->response->status();

                    return $status === 408 || $status === 429 || $status >= 500;
                }

                return false;
            },
            throw: false,
        );
    }

    /**
     * Map provider-specific finish reasons onto one canonical vocabulary so
     * downstream billing logic (is_billable refusal checks) works for every
     * provider. Google reports `SAFETY`/`PROHIBITED_CONTENT` in uppercase,
     * Anthropic says `end_turn`/`refusal`, OpenAI says `length`/`content_filter`
     * — without this, a Gemini safety block would slip past the refusal
     * blocklist and get billed to the user.
     *
     * Canonical values: stop | max_tokens | content_filter | tool_use,
     * with unknown reasons passed through lowercased.
     */
    protected function normalizeFinishReason(?string $reason): string
    {
        $lower = strtolower(trim((string) $reason));

        return match ($lower) {
            '', 'stop', 'end_turn', 'stop_sequence', 'eos' => 'stop',
            'length', 'max_tokens', 'model_length' => 'max_tokens',
            'content_filter', 'safety', 'blocked', 'blocklist', 'refusal',
            'prohibited_content', 'recitation', 'image_safety', 'spii' => 'content_filter',
            'tool_calls', 'tool_use', 'function_call' => 'tool_use',
            default => $lower,
        };
    }

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
