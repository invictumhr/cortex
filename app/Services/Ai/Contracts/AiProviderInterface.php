<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\AiResponse;

interface AiProviderInterface
{
    /**
     * Send a chat completion request and return the full response.
     *
     * @param  \App\Services\Ai\Data\AiMessage[]  $messages
     * @param  array<string, mixed>  $options
     */
    public function sendMessage(string $systemPrompt, array $messages, array $options = []): AiResponse;

    public function supportsVision(): bool;

    public function supportsTools(): bool;

    public function getMaxTokens(): int;

    public function calculateCost(int $inputTokens, int $outputTokens): float;
}
