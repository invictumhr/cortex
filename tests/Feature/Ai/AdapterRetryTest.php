<?php

namespace Tests\Feature\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Adapters\AnthropicAdapter;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdapterRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Zero backoff so retries don't slow the suite down.
        config(['cortex.provider_retry_backoff_ms' => [0, 0]]);
    }

    private function makeModel(): AiModel
    {
        $provider = AiProvider::create([
            'slug' => 'anthropic-test-'.uniqid(),
            'name' => 'Anthropic Test',
            'api_key' => 'test-key',
            'is_active' => true,
        ]);

        return AiModel::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Claude Test',
            'model_string' => 'claude-test-1',
            'input_cost_per_1m_tokens' => 3.0,
            'output_cost_per_1m_tokens' => 15.0,
            'is_active' => true,
        ]);
    }

    private function successBody(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => 'Hello from Claude.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'model' => 'claude-test-1',
            'stop_reason' => 'end_turn',
        ];
    }

    public function test_transient_429_is_retried_and_succeeds(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push($this->successBody(), 200),
        ]);

        $adapter = new AnthropicAdapter($this->makeModel());
        $response = $adapter->sendMessage('system', [AiMessage::user('hi')]);

        $this->assertSame('Hello from Claude.', $response->content);
        $this->assertSame('stop', $response->finishReason, 'end_turn normalizes to stop');
        Http::assertSentCount(2);
    }

    public function test_5xx_is_retried_then_final_failure_throws_provider_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => 'overloaded'], 503)
                ->push(['error' => 'overloaded'], 503)
                ->push(['error' => 'overloaded'], 503),
        ]);

        $adapter = new AnthropicAdapter($this->makeModel());

        try {
            $adapter->sendMessage('system', [AiMessage::user('hi')]);
            $this->fail('Expected AiProviderException after exhausting retries.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        // Two backoff entries = three total attempts.
        Http::assertSentCount(3);
    }

    public function test_client_error_400_is_not_retried(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $adapter = new AnthropicAdapter($this->makeModel());

        try {
            $adapter->sendMessage('system', [AiMessage::user('hi')]);
            $this->fail('Expected AiProviderException for 400.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        Http::assertSentCount(1);
    }
}
