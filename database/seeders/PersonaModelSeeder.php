<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\Persona;
use Illuminate\Database\Seeder;

class PersonaModelSeeder extends Seeder
{
    /**
     * Authoritative persona -> model mapping. Each persona runs on a model
     * matched to its cognitive load and character, spread across all six
     * provider families so the boardroom genuinely mixes model priors.
     */
    public function run(): void
    {
        $map = [
            // Flagship reasoning — strategy & security
            'marco' => 'claude-opus-4-7',
            'zara' => 'claude-opus-4-7',
            // OpenAI reasoning model — architecture & mathematics
            'ana' => 'o3',
            'ada' => 'o3',
            // Strong general — product & legal
            'helena' => 'claude-sonnet-4-6',
            'iris' => 'claude-sonnet-4-6',
            // Legacy Opus — deep science
            'nikola' => 'claude-opus-4-20250514',
            'chen' => 'claude-opus-4-20250514',
            // Large-context analytical — systems & futures
            'darwin' => 'gpt-4.1',
            'hawking' => 'gpt-4.1',
            // Legacy Sonnet — delivery & ethics
            'max' => 'claude-sonnet-4-20250514',
            'rosa' => 'claude-sonnet-4-20250514',
            // Creative/visual
            'luna' => 'gpt-4o',
            'frida' => 'gpt-4o',
            // Broad, multilingual — UX & localization
            'miro' => 'gemini-2.5-flash',
            'yuki' => 'gemini-2.5-flash',
            // Edgy / adversarial — skeptic, red team, aggressive sales
            'viktor' => 'grok-3',
            'ghost' => 'grok-3',
            'leo' => 'grok-3',
            // Solid generalist — frontend, devops, copy
            'kai' => 'mistral-large-latest',
            'rex' => 'mistral-large-latest',
            'oscar' => 'mistral-large-latest',
            // Capable & economical — QA, education, energy
            'petra' => 'deepseek-chat',
            'mara' => 'deepseek-chat',
            'tesla' => 'deepseek-chat',
            // Fast & light — conversational & growth, plus the scribe
            'sophia' => 'claude-haiku-4-5-20251001',
            'kira' => 'claude-haiku-4-5-20251001',
            'scribe' => 'claude-haiku-4-5-20251001',
            // Light tier — non-expert client & playful game designer
            'dragan' => 'gpt-4o-mini',
            'pixel' => 'gpt-4o-mini',
        ];

        $modelIds = AiModel::query()->pluck('id', 'model_string');

        foreach ($map as $slug => $modelString) {
            $modelId = $modelIds[$modelString] ?? null;

            if ($modelId !== null) {
                Persona::where('slug', $slug)->update(['ai_model_id' => $modelId]);
            }
        }
    }
}
