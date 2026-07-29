<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * July 2026 model-catalog refresh (prices verified 2026-07-29 against
 * official provider pricing/deprecation pages). Production-safe data
 * migration — same idempotent upsert pattern as 2026_06_08.
 *
 * What it fixes:
 *  - o3 price was 5x reality ($10/$40 seeded vs $2/$8 actual since Jun 2025);
 *    o3 is deprecated (API shutdown 2026-12-11) → persona Ana moves to its
 *    official replacement gpt-5.6-sol.
 *  - Gemini 2.5 family (pro/flash/flash-lite) deprecated, shutdown announced
 *    for 2026-10-16 → deactivated; Miro moves to gemini-3.6-flash.
 *  - grok-3 retired 2026-05-15 → deactivated (dev DB had it active).
 *  - mistral-small-latest resolves to Small 4 ($0.15/$0.60) since March —
 *    stale $0.10/$0.30 row under-counted provider cost.
 *  - Context-window corrections (sonnet-4-6 = 1M, Mistral = 256k,
 *    gpt-5.4-nano = 400k).
 *  - New generation added: Claude Opus 5 / Sonnet 5, GPT-5.6 Sol/Terra/Luna,
 *    Gemini 3.6 Flash + 3.5/3.1 Flash Lite, Grok 4.5 (inactive until EU
 *    availability is confirmed).
 *
 * Keep in sync with AiModelSeeder + PersonaModelSeeder (inlined here because
 * Database\Seeders is not autoloaded in production).
 */
return new class extends Migration
{
    public function up(): void
    {
        $providerIds = DB::table('ai_providers')->pluck('id', 'slug')->all();
        $now = now();

        // ── 1. Model catalog upsert ─────────────────────────────────────
        $models = [
            // Anthropic
            ['provider' => 'anthropic', 'name' => 'Claude Opus 5',    'model_string' => 'claude-opus-5',             'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00,  'out' => 25.00, 'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Sonnet 5',  'model_string' => 'claude-sonnet-5',           'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 3.00,  'out' => 15.00, 'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4.8',  'model_string' => 'claude-opus-4-8',           'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00,  'out' => 25.00, 'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4.7',  'model_string' => 'claude-opus-4-7',           'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00,  'out' => 25.00, 'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Sonnet 4.6','model_string' => 'claude-sonnet-4-6',         'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 3.00,  'out' => 15.00, 'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Haiku 4.5', 'model_string' => 'claude-haiku-4-5-20251001', 'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 1.00,  'out' => 5.00,  'active' => true],
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4',    'model_string' => 'claude-opus-4-20250514',    'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 15.00, 'out' => 75.00, 'active' => false],
            ['provider' => 'anthropic', 'name' => 'Claude Sonnet 4',  'model_string' => 'claude-sonnet-4-20250514',  'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 3.00,  'out' => 15.00, 'active' => false],

            // OpenAI
            ['provider' => 'openai', 'name' => 'GPT-5.6 Sol',   'model_string' => 'gpt-5.6-sol',   'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00, 'out' => 30.00, 'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.6 Terra', 'model_string' => 'gpt-5.6-terra', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 2.50, 'out' => 15.00, 'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.6 Luna',  'model_string' => 'gpt-5.6-luna',  'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.00, 'out' => 6.00,  'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.5',      'model_string' => 'gpt-5.5',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00, 'out' => 30.00, 'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.4',      'model_string' => 'gpt-5.4',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 2.50, 'out' => 15.00, 'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.4 mini', 'model_string' => 'gpt-5.4-mini', 'vision' => true, 'tools' => true, 'context' => 400000,  'in' => 0.75, 'out' => 4.50,  'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-5.4 nano', 'model_string' => 'gpt-5.4-nano', 'vision' => true, 'tools' => true, 'context' => 400000,  'in' => 0.20, 'out' => 1.25,  'active' => true],
            ['provider' => 'openai', 'name' => 'o3',           'model_string' => 'o3',           'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 2.00, 'out' => 8.00,  'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-4.1',      'model_string' => 'gpt-4.1',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 2.00, 'out' => 8.00,  'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-4o',       'model_string' => 'gpt-4o',       'vision' => true, 'tools' => true, 'context' => 128000,  'in' => 2.50, 'out' => 10.00, 'active' => true],
            ['provider' => 'openai', 'name' => 'GPT-4o mini',  'model_string' => 'gpt-4o-mini',  'vision' => true, 'tools' => true, 'context' => 128000,  'in' => 0.15, 'out' => 0.60,  'active' => true],

            // xAI
            ['provider' => 'xai', 'name' => 'Grok 4.3', 'model_string' => 'grok-4.3', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.25, 'out' => 2.50,  'active' => true],
            ['provider' => 'xai', 'name' => 'Grok 4.5', 'model_string' => 'grok-4.5', 'vision' => true, 'tools' => true, 'context' => 500000,  'in' => 2.00, 'out' => 6.00,  'active' => false],
            ['provider' => 'xai', 'name' => 'Grok 3',   'model_string' => 'grok-3',   'vision' => true, 'tools' => true, 'context' => 131000,  'in' => 3.00, 'out' => 15.00, 'active' => false],

            // Google
            ['provider' => 'google', 'name' => 'Gemini 3.6 Flash',      'model_string' => 'gemini-3.6-flash',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.50, 'out' => 7.50,  'active' => true],
            ['provider' => 'google', 'name' => 'Gemini 3.5 Flash',      'model_string' => 'gemini-3.5-flash',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.50, 'out' => 9.00,  'active' => true],
            ['provider' => 'google', 'name' => 'Gemini 3.5 Flash Lite', 'model_string' => 'gemini-3.5-flash-lite', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.30, 'out' => 2.50,  'active' => true],
            ['provider' => 'google', 'name' => 'Gemini 3.1 Flash Lite', 'model_string' => 'gemini-3.1-flash-lite', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.25, 'out' => 1.50,  'active' => true],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Pro',        'model_string' => 'gemini-2.5-pro',        'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.25, 'out' => 10.00, 'active' => false],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Flash',      'model_string' => 'gemini-2.5-flash',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.30, 'out' => 2.50,  'active' => false],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Flash Lite', 'model_string' => 'gemini-2.5-flash-lite', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.10, 'out' => 0.40,  'active' => false],

            // Mistral
            ['provider' => 'mistral', 'name' => 'Mistral Medium 3.5', 'model_string' => 'mistral-medium-latest', 'vision' => true, 'tools' => true, 'context' => 256000, 'in' => 1.50, 'out' => 7.50, 'active' => true],
            ['provider' => 'mistral', 'name' => 'Mistral Large 3',    'model_string' => 'mistral-large-latest',  'vision' => true, 'tools' => true, 'context' => 256000, 'in' => 0.50, 'out' => 1.50, 'active' => true],
            ['provider' => 'mistral', 'name' => 'Mistral Small 4',    'model_string' => 'mistral-small-latest',  'vision' => true, 'tools' => true, 'context' => 256000, 'in' => 0.15, 'out' => 0.60, 'active' => true],

            // DeepSeek
            ['provider' => 'deepseek', 'name' => 'DeepSeek V4 Flash', 'model_string' => 'deepseek-v4-flash', 'vision' => false, 'tools' => true, 'context' => 1000000, 'in' => 0.14,  'out' => 0.28, 'active' => true],
            ['provider' => 'deepseek', 'name' => 'DeepSeek V4 Pro',   'model_string' => 'deepseek-v4-pro',   'vision' => false, 'tools' => true, 'context' => 1000000, 'in' => 0.435, 'out' => 0.87, 'active' => true],
            ['provider' => 'deepseek', 'name' => 'DeepSeek V3',       'model_string' => 'deepseek-chat',     'vision' => false, 'tools' => true, 'context' => 64000,   'in' => 0.27,  'out' => 1.10, 'active' => false],
        ];

        foreach ($models as $m) {
            if (! isset($providerIds[$m['provider']])) {
                continue;
            }

            $existing = DB::table('ai_models')
                ->where('ai_provider_id', $providerIds[$m['provider']])
                ->where('model_string', $m['model_string'])
                ->first();

            $attributes = [
                'name' => $m['name'],
                'supports_vision' => $m['vision'],
                'supports_tools' => $m['tools'],
                'max_context_tokens' => $m['context'],
                'input_cost_per_1m_tokens' => $m['in'],
                'output_cost_per_1m_tokens' => $m['out'],
                'is_active' => $m['active'],
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('ai_models')->where('id', $existing->id)->update($attributes);
            } else {
                DB::table('ai_models')->insert($attributes + [
                    'ai_provider_id' => $providerIds[$m['provider']],
                    'model_string' => $m['model_string'],
                    'created_at' => $now,
                ]);
            }
        }

        // ── 2. Persona default-model remaps ─────────────────────────────
        // Only slugs whose model changed. Per-chat pivot overrides on the
        // now-inactive models are handled at runtime: PersonaResponder's
        // pivot lookup requires is_active=true, so old chats fall through to
        // these new defaults automatically.
        $personaMap = [
            'marco' => 'claude-opus-5',
            'chen' => 'claude-opus-5',
            'chair' => 'claude-opus-5',
            'helena' => 'claude-sonnet-5',
            'max' => 'claude-sonnet-5',
            'ana' => 'gpt-5.6-sol',
            'luna' => 'gpt-5.6-luna',
            'miro' => 'gemini-3.6-flash',
        ];

        $modelIds = DB::table('ai_models')->pluck('id', 'model_string')->all();

        foreach ($personaMap as $slug => $modelString) {
            if (isset($modelIds[$modelString])) {
                DB::table('personas')
                    ->where('slug', $slug)
                    ->update(['ai_model_id' => $modelIds[$modelString], 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Data refresh — no structural change to roll back. Restoring the
        // stale May catalog would reintroduce wrong prices; intentionally a
        // no-op.
    }
};
