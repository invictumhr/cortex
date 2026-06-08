<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // ── Anthropic ───────────────────────────────────────────────
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4.8',  'model_string' => 'claude-opus-4-8',           'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00,  'out' => 25.00],
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4.7',  'model_string' => 'claude-opus-4-7',           'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00,  'out' => 25.00],
            ['provider' => 'anthropic', 'name' => 'Claude Sonnet 4.6','model_string' => 'claude-sonnet-4-6',         'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 3.00,  'out' => 15.00],
            ['provider' => 'anthropic', 'name' => 'Claude Haiku 4.5', 'model_string' => 'claude-haiku-4-5-20251001', 'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 1.00,  'out' => 5.00],
            // Anthropic legacy — retiring June 15 2026
            ['provider' => 'anthropic', 'name' => 'Claude Opus 4',   'model_string' => 'claude-opus-4-20250514',    'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 15.00, 'out' => 75.00, 'active' => false],
            ['provider' => 'anthropic', 'name' => 'Claude Sonnet 4', 'model_string' => 'claude-sonnet-4-20250514',  'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 3.00,  'out' => 15.00, 'active' => false],

            // ── OpenAI ──────────────────────────────────────────────────
            ['provider' => 'openai', 'name' => 'GPT-5.5',      'model_string' => 'gpt-5.5',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 5.00, 'out' => 30.00],
            ['provider' => 'openai', 'name' => 'GPT-5.4',      'model_string' => 'gpt-5.4',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 2.50, 'out' => 15.00],
            ['provider' => 'openai', 'name' => 'GPT-5.4 mini', 'model_string' => 'gpt-5.4-mini', 'vision' => true, 'tools' => true, 'context' => 400000,  'in' => 0.75, 'out' => 4.50],
            ['provider' => 'openai', 'name' => 'GPT-5.4 nano', 'model_string' => 'gpt-5.4-nano', 'vision' => true, 'tools' => true, 'context' => 128000,  'in' => 0.20, 'out' => 1.25],
            ['provider' => 'openai', 'name' => 'o3',           'model_string' => 'o3',           'vision' => true, 'tools' => true, 'context' => 200000,  'in' => 10.00,'out' => 40.00],
            ['provider' => 'openai', 'name' => 'GPT-4.1',      'model_string' => 'gpt-4.1',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 2.00, 'out' => 8.00],
            ['provider' => 'openai', 'name' => 'GPT-4o',       'model_string' => 'gpt-4o',       'vision' => true, 'tools' => true, 'context' => 128000,  'in' => 2.50, 'out' => 10.00],
            ['provider' => 'openai', 'name' => 'GPT-4o mini',  'model_string' => 'gpt-4o-mini',  'vision' => true, 'tools' => true, 'context' => 128000,  'in' => 0.15, 'out' => 0.60],

            // ── xAI ─────────────────────────────────────────────────────
            ['provider' => 'xai', 'name' => 'Grok 4.3', 'model_string' => 'grok-4.3', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.25, 'out' => 2.50],
            ['provider' => 'xai', 'name' => 'Grok 3',   'model_string' => 'grok-3',   'vision' => true, 'tools' => true, 'context' => 131000,  'in' => 3.00, 'out' => 15.00],

            // ── Google ──────────────────────────────────────────────────
            ['provider' => 'google', 'name' => 'Gemini 3.5 Flash',     'model_string' => 'gemini-3.5-flash',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.50, 'out' => 9.00],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Pro',       'model_string' => 'gemini-2.5-pro',        'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 1.25, 'out' => 10.00],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Flash',     'model_string' => 'gemini-2.5-flash',      'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.30, 'out' => 2.50],
            ['provider' => 'google', 'name' => 'Gemini 2.5 Flash Lite','model_string' => 'gemini-2.5-flash-lite', 'vision' => true, 'tools' => true, 'context' => 1000000, 'in' => 0.10, 'out' => 0.40],

            // ── Mistral ─────────────────────────────────────────────────
            ['provider' => 'mistral', 'name' => 'Mistral Medium 3.5', 'model_string' => 'mistral-medium-latest', 'vision' => true, 'tools' => true, 'context' => 128000, 'in' => 1.50, 'out' => 7.50],
            ['provider' => 'mistral', 'name' => 'Mistral Large',      'model_string' => 'mistral-large-latest',  'vision' => true, 'tools' => true, 'context' => 128000, 'in' => 0.50, 'out' => 1.50],
            ['provider' => 'mistral', 'name' => 'Mistral Small 4',    'model_string' => 'mistral-small-latest',  'vision' => true, 'tools' => true, 'context' => 128000, 'in' => 0.10, 'out' => 0.30],

            // ── DeepSeek ────────────────────────────────────────────────
            ['provider' => 'deepseek', 'name' => 'DeepSeek V4 Flash', 'model_string' => 'deepseek-v4-flash', 'vision' => false, 'tools' => true, 'context' => 1000000, 'in' => 0.14, 'out' => 0.28],
            ['provider' => 'deepseek', 'name' => 'DeepSeek V4 Pro',   'model_string' => 'deepseek-v4-pro',   'vision' => false, 'tools' => true, 'context' => 1000000, 'in' => 0.44, 'out' => 0.87],
            // DeepSeek legacy — deprecated July 24 2026
            ['provider' => 'deepseek', 'name' => 'DeepSeek V3', 'model_string' => 'deepseek-chat', 'vision' => false, 'tools' => true, 'context' => 64000, 'in' => 0.27, 'out' => 1.10, 'active' => false],
        ];

        $providerIds = AiProvider::query()->pluck('id', 'slug');

        foreach ($models as $m) {
            if (! isset($providerIds[$m['provider']])) {
                continue;
            }

            AiModel::updateOrCreate(
                [
                    'ai_provider_id' => $providerIds[$m['provider']],
                    'model_string' => $m['model_string'],
                ],
                [
                    'name' => $m['name'],
                    'supports_vision' => $m['vision'],
                    'supports_tools' => $m['tools'],
                    'max_context_tokens' => $m['context'],
                    'input_cost_per_1m_tokens' => $m['in'],
                    'output_cost_per_1m_tokens' => $m['out'],
                    'is_active' => $m['active'] ?? true,
                ]
            );
        }
    }
}
