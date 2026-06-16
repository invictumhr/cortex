<?php

namespace Tests\Feature\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Adapters\AnthropicAdapter;
use App\Services\Ai\Data\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicPromptCachingTest extends TestCase
{
    use RefreshDatabase;

    private function makeModel(): AiModel
    {
        $provider = AiProvider::create([
            'slug' => 'anthropic-cache-'.uniqid(),
            'name' => 'Anthropic Cache Test',
            'api_key' => 'test-key',
            'is_active' => true,
        ]);

        return AiModel::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Claude Cache Test',
            'model_string' => 'claude-cache-test',
            'input_cost_per_1m_tokens' => 3.0,
            'output_cost_per_1m_tokens' => 15.0,
            'is_active' => true,
        ]);
    }

    private function fakeResponse(array $usage): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Odgovor.']],
                'usage' => $usage,
                'model' => 'claude-cache-test',
                'stop_reason' => 'end_turn',
            ], 200),
        ]);
    }

    public function test_system_block_carries_cache_control_when_enabled(): void
    {
        config(['cortex.prompt_caching' => true]);
        $this->fakeResponse(['input_tokens' => 10, 'output_tokens' => 5]);

        (new AnthropicAdapter($this->makeModel()))
            ->sendMessage('Dugi system prompt persone.', [AiMessage::user('hi')]);

        Http::assertSent(function ($request) {
            $system = $request->data()['system'];

            return is_array($system)
                && $system[0]['type'] === 'text'
                && $system[0]['text'] === 'Dugi system prompt persone.'
                && $system[0]['cache_control'] === ['type' => 'ephemeral'];
        });
    }

    public function test_system_stays_plain_string_when_caching_disabled(): void
    {
        config(['cortex.prompt_caching' => false]);
        $this->fakeResponse(['input_tokens' => 10, 'output_tokens' => 5]);

        (new AnthropicAdapter($this->makeModel()))
            ->sendMessage('System prompt.', [AiMessage::user('hi')]);

        Http::assertSent(fn ($request) => $request->data()['system'] === 'System prompt.');
    }

    public function test_cache_token_accounting_folds_into_billable_input(): void
    {
        config(['cortex.prompt_caching' => true]);
        // Anthropic excludes cached tokens from input_tokens: 100 uncached,
        // 1000 written to cache (1.25×), 2000 read from cache (0.1×).
        $this->fakeResponse([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 1000,
            'cache_read_input_tokens' => 2000,
        ]);

        $response = (new AnthropicAdapter($this->makeModel()))
            ->sendMessage('System.', [AiMessage::user('hi')]);

        $this->assertSame(3100, $response->inputTokens, 'stats input = full context size');
        $this->assertSame(1000, $response->cacheCreationInputTokens);
        $this->assertSame(2000, $response->cacheReadInputTokens);
        // 100 + 1.25×1000 + 0.1×2000 = 1550
        $this->assertSame(1550, $response->billableInputTokens());
    }

    public function test_billable_input_defaults_to_input_tokens_without_cache_fields(): void
    {
        config(['cortex.prompt_caching' => true]);
        $this->fakeResponse(['input_tokens' => 400, 'output_tokens' => 50]);

        $response = (new AnthropicAdapter($this->makeModel()))
            ->sendMessage('System.', [AiMessage::user('hi')]);

        $this->assertSame(400, $response->inputTokens);
        $this->assertSame(400, $response->billableInputTokens());
    }
}
