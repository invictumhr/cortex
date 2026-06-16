<?php

namespace Tests\Unit\Ai;

use App\Models\AiModel;
use App\Services\Ai\Adapters\AbstractAdapter;
use App\Services\Ai\Data\AiResponse;
use PHPUnit\Framework\TestCase;

class FinishReasonNormalizationTest extends TestCase
{
    private function adapter(): AbstractAdapter
    {
        return new class(new AiModel) extends AbstractAdapter
        {
            protected function defaultBaseUrl(): string
            {
                return 'https://example.test';
            }

            public function sendMessage(string $systemPrompt, array $messages, array $options = []): AiResponse
            {
                return new AiResponse('');
            }

            public function normalize(?string $reason): string
            {
                return $this->normalizeFinishReason($reason);
            }
        };
    }

    public function test_google_uppercase_safety_reasons_map_to_content_filter(): void
    {
        $adapter = $this->adapter();

        // Gemini reports finish reasons in UPPERCASE — before normalization a
        // SAFETY block slipped past the billing refusal check and got billed.
        $this->assertSame('content_filter', $adapter->normalize('SAFETY'));
        $this->assertSame('content_filter', $adapter->normalize('PROHIBITED_CONTENT'));
        $this->assertSame('content_filter', $adapter->normalize('RECITATION'));
        $this->assertSame('content_filter', $adapter->normalize('BLOCKLIST'));
    }

    public function test_provider_specific_stop_and_length_reasons_are_canonical(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('stop', $adapter->normalize('end_turn'));       // Anthropic
        $this->assertSame('stop', $adapter->normalize('stop_sequence'));  // Anthropic
        $this->assertSame('stop', $adapter->normalize('STOP'));           // Google
        $this->assertSame('stop', $adapter->normalize('stop'));           // OpenAI
        $this->assertSame('stop', $adapter->normalize(null));
        $this->assertSame('stop', $adapter->normalize(''));

        $this->assertSame('max_tokens', $adapter->normalize('length'));      // OpenAI
        $this->assertSame('max_tokens', $adapter->normalize('max_tokens'));  // Anthropic
        $this->assertSame('max_tokens', $adapter->normalize('MAX_TOKENS'));  // Google
    }

    public function test_refusal_and_filter_aliases_map_to_content_filter(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('content_filter', $adapter->normalize('content_filter')); // OpenAI
        $this->assertSame('content_filter', $adapter->normalize('refusal'));        // Anthropic
    }

    public function test_unknown_reasons_pass_through_lowercased(): void
    {
        $this->assertSame('weird_new_reason', $this->adapter()->normalize('WEIRD_NEW_REASON'));
    }
}
