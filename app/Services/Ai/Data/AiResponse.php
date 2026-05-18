<?php

namespace App\Services\Ai\Data;

class AiResponse
{
    /**
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
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
