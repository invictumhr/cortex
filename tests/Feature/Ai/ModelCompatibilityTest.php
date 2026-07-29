<?php

namespace Tests\Feature\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Adapters\AnthropicAdapter;
use App\Services\Ai\Adapters\GoogleAdapter;
use App\Services\Ai\Adapters\MistralAdapter;
use App\Services\Ai\Adapters\OpenAiAdapter;
use App\Services\Ai\Adapters\XAiAdapter;
use App\Services\Ai\Data\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression net for per-generation request shapes. The July 2026 audit
 * showed every gpt-5.x persona silently falling back to gpt-4o-mini because
 * the adapter sent parameters the new generation rejects — these tests pin
 * the exact payload rules per provider/generation.
 */
class ModelCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeModel(string $providerSlug, string $modelString): AiModel
    {
        $provider = AiProvider::firstOrCreate(
            ['slug' => $providerSlug.'-compat-'.uniqid()],
            ['name' => ucfirst($providerSlug).' Compat', 'api_key' => 'test-key', 'is_active' => true],
        );

        return AiModel::create([
            'ai_provider_id' => $provider->id,
            'name' => $modelString,
            'model_string' => $modelString,
            'input_cost_per_1m_tokens' => 1.0,
            'output_cost_per_1m_tokens' => 2.0,
            'is_active' => true,
        ]);
    }

    private function openAiStyleResponse(): array
    {
        return [
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'model' => 'x',
        ];
    }

    private function sentPayload(): array
    {
        $payload = null;
        Http::assertSent(function ($request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return $payload;
    }

    private array $options = ['max_tokens' => 900, 'temperature' => 0.8];

    // ── OpenAI ──────────────────────────────────────────────────────────

    public function test_gpt5_family_uses_completion_tokens_effort_and_no_temperature(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiStyleResponse())]);

        (new OpenAiAdapter($this->makeModel('openai', 'gpt-5.6-sol')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $p = $this->sentPayload();
        $this->assertSame(900, $p['max_completion_tokens']);
        $this->assertSame('low', $p['reasoning_effort']);
        $this->assertArrayNotHasKey('max_tokens', $p, 'gpt-5.x rejects max_tokens');
        $this->assertArrayNotHasKey('temperature', $p, 'gpt-5.x rejects temperature');
    }

    public function test_o_series_keeps_working_shape_without_reasoning_effort(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiStyleResponse())]);

        (new OpenAiAdapter($this->makeModel('openai', 'o3')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $p = $this->sentPayload();
        $this->assertSame(900, $p['max_completion_tokens']);
        $this->assertArrayNotHasKey('reasoning_effort', $p, 'o3 defaults are known-good — leave them');
        $this->assertArrayNotHasKey('temperature', $p);
    }

    public function test_legacy_gpt4_models_keep_max_tokens_and_temperature(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiStyleResponse())]);

        (new OpenAiAdapter($this->makeModel('openai', 'gpt-4o-mini')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $p = $this->sentPayload();
        $this->assertSame(900, $p['max_tokens']);
        $this->assertSame(0.8, $p['temperature']);
        $this->assertArrayNotHasKey('reasoning_effort', $p);
    }

    // ── Anthropic ───────────────────────────────────────────────────────

    private function anthropicResponse(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'end_turn',
        ];
    }

    public function test_claude_5_generation_disables_thinking_and_skips_temperature(): void
    {
        foreach (['claude-opus-5', 'claude-sonnet-5'] as $modelString) {
            Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse())]);

            (new AnthropicAdapter($this->makeModel('anthropic', $modelString)))
                ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

            $p = $this->sentPayload();
            $this->assertSame(['type' => 'disabled'], $p['thinking'], "$modelString must disable default-on thinking");
            $this->assertArrayNotHasKey('temperature', $p, "$modelString rejects temperature");
        }
    }

    public function test_claude_4x_shapes_are_unchanged(): void
    {
        // Opus 4.8: no temperature (existing rule), and no thinking key.
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse())]);
        (new AnthropicAdapter($this->makeModel('anthropic', 'claude-opus-4-8')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);
        $p = $this->sentPayload();
        $this->assertArrayNotHasKey('temperature', $p);
        $this->assertArrayNotHasKey('thinking', $p);

        // Sonnet 4.6: temperature still allowed.
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicResponse())]);
        (new AnthropicAdapter($this->makeModel('anthropic', 'claude-sonnet-4-6')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);
        $p = $this->sentPayload();
        $this->assertSame(0.8, $p['temperature']);
        $this->assertArrayNotHasKey('thinking', $p);
    }

    // ── Google ──────────────────────────────────────────────────────────

    private function googleResponse(): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ];
    }

    public function test_gemini_3x_uses_thinking_level_and_no_temperature(): void
    {
        foreach (['gemini-3.6-flash', 'gemini-3.1-flash-lite'] as $modelString) {
            Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->googleResponse())]);

            (new GoogleAdapter($this->makeModel('google', $modelString)))
                ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

            $p = $this->sentPayload();
            $cfg = $p['generationConfig'];
            $this->assertSame(['thinkingLevel' => 'minimal'], $cfg['thinkingConfig'], "$modelString uses thinkingLevel");
            $this->assertArrayNotHasKey('temperature', $cfg, "$modelString must not receive temperature");
        }
    }

    public function test_gemini_2x_flash_keeps_thinking_budget_and_temperature(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->googleResponse())]);

        (new GoogleAdapter($this->makeModel('google', 'gemini-2.5-flash')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $cfg = $this->sentPayload()['generationConfig'];
        $this->assertSame(['thinkingBudget' => 0], $cfg['thinkingConfig']);
        $this->assertSame(0.8, $cfg['temperature']);
    }

    public function test_gemini_2x_pro_gets_no_thinking_config(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->googleResponse())]);

        (new GoogleAdapter($this->makeModel('google', 'gemini-2.5-pro')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $cfg = $this->sentPayload()['generationConfig'];
        $this->assertArrayNotHasKey('thinkingConfig', $cfg, '2.x pro cannot disable thinking — send nothing');
    }

    // ── xAI / Mistral ───────────────────────────────────────────────────

    public function test_xai_always_caps_reasoning_effort(): void
    {
        Http::fake(['api.x.ai/*' => Http::response($this->openAiStyleResponse())]);

        (new XAiAdapter($this->makeModel('xai', 'grok-4.3')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $p = $this->sentPayload();
        $this->assertSame('low', $p['reasoning_effort'], 'xAI defaults to high — must be capped');
        $this->assertSame(900, $p['max_tokens']);
        $this->assertSame(0.8, $p['temperature']);
    }

    public function test_mistral_gets_no_reasoning_effort(): void
    {
        Http::fake(['api.mistral.ai/*' => Http::response($this->openAiStyleResponse())]);

        (new MistralAdapter($this->makeModel('mistral', 'mistral-large-latest')))
            ->sendMessage('sys', [AiMessage::user('hi')], $this->options);

        $p = $this->sentPayload();
        $this->assertArrayNotHasKey('reasoning_effort', $p);
        $this->assertSame(0.8, $p['temperature']);
    }

    // ── Katalog (migracija 2026_07_29) ──────────────────────────────────

    public function test_catalog_migration_state_is_applied(): void
    {
        // RefreshDatabase runs all migrations, including the July catalog
        // refresh — assert its most important effects.
        $bySlug = fn (string $s) => AiModel::where('model_string', $s)->first();

        $this->assertTrue((bool) $bySlug('claude-opus-5')?->is_active, 'opus-5 seeded active');
        $this->assertTrue((bool) $bySlug('gpt-5.6-sol')?->is_active, 'gpt-5.6-sol seeded active');
        $this->assertTrue((bool) $bySlug('gemini-3.6-flash')?->is_active, 'gemini-3.6-flash seeded active');

        $o3 = $bySlug('o3');
        $this->assertSame(2.0, (float) $o3->input_cost_per_1m_tokens, 'o3 price fixed (was 5x reality)');
        $this->assertSame(8.0, (float) $o3->output_cost_per_1m_tokens);

        $this->assertFalse((bool) $bySlug('gemini-2.5-pro')?->is_active, '2.5 family deactivated (EOL 2026-10-16)');
        $this->assertFalse((bool) $bySlug('grok-3')?->is_active, 'grok-3 retired');
        $this->assertFalse((bool) $bySlug('grok-4.5')?->is_active, 'grok-4.5 held until EU availability confirmed');

        $this->assertSame(
            0.15,
            (float) $bySlug('mistral-small-latest')->input_cost_per_1m_tokens,
            'mistral-small repriced to Small 4 rates',
        );

        // Persona remaps off deprecated models.
        $personaModel = fn (string $slug) => \App\Models\Persona::where('slug', $slug)->first()?->aiModel?->model_string;
        $this->assertSame('gpt-5.6-sol', $personaModel('ana'), 'ana moved off deprecated o3');
        $this->assertSame('gemini-3.6-flash', $personaModel('miro'), 'miro moved off deprecated gemini-2.5-flash');
        $this->assertSame('claude-opus-5', $personaModel('chair'));
    }
}
