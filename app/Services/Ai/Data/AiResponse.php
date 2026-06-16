<?php

namespace App\Services\Ai\Data;

class AiResponse
{
    /**
     * `inputTokens` is the FULL context size (uncached + cache-write +
     * cache-read tokens) so chat token statistics stay truthful. The cache
     * splits are kept separately because providers price them differently;
     * `billableInputTokens` carries the adapter-computed input-equivalent
     * used for cost math (null = no caching, falls back to inputTokens).
     *
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $content,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public string $model = '',
        public string $finishReason = 'stop',
        public int $responseTimeMs = 0,
        public array $raw = [],
        public int $cacheCreationInputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public ?int $billableInputTokens = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Input tokens expressed at the model's standard input rate, with any
     * provider cache pricing already folded in by the adapter. Feed this to
     * AiModel::calculateCost() — using raw `inputTokens` would over-bill
     * cache reads and under-bill cache writes.
     */
    public function billableInputTokens(): int
    {
        return $this->billableInputTokens ?? $this->inputTokens;
    }
}
