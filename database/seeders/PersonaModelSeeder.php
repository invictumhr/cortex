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
            // Flagship reasoning — strategy & security. Opus 5 je drop-in
            // upgrade Opusa 4.8 po istoj cijeni ($5/$25).
            'marco' => 'claude-opus-5',
            'zara' => 'claude-opus-4-7',
            // OpenAI reasoning — architecture & mathematics. Ana je bila na
            // o3 (deprecated, gasi se 2026-12-11) → službena zamjena.
            'ana' => 'gpt-5.6-sol',
            'ada' => 'gpt-5.5',
            // Strong general — product & legal. Sonnet 5 = ista cijena kao
            // 4.6, novija generacija.
            'helena' => 'claude-sonnet-5',
            'iris' => 'gpt-5.4',
            // Deep science
            'nikola' => 'claude-opus-4-7',
            'chen' => 'claude-opus-5',
            // Large-context analytical — systems & futures
            'darwin' => 'gpt-4.1',
            'hawking' => 'gpt-5.4',
            // Delivery & ethics
            'max' => 'claude-sonnet-5',
            'rosa' => 'gpt-5.4',
            // Creative/visual — Luna na novom kreativnom mid-tieru ($1/$6)
            'luna' => 'gpt-5.6-luna',
            'frida' => 'gemini-3.5-flash',
            // Broad, multilingual — UX & localization. Miro je bio na
            // gemini-2.5-flash (deprecated, gašenje 2026-10-16).
            'miro' => 'gemini-3.6-flash',
            'yuki' => 'gemini-3.5-flash',
            // Edgy / adversarial — skeptic, red team, aggressive sales
            'viktor' => 'grok-4.3',
            'ghost' => 'grok-4.3',
            'leo' => 'grok-4.3',
            // Solid generalist — frontend, devops, copy
            'kai' => 'mistral-medium-latest',
            'rex' => 'mistral-large-latest',
            'oscar' => 'mistral-large-latest',
            // Capable & economical — QA, education, energy
            'petra' => 'deepseek-v4-flash',
            'mara' => 'deepseek-v4-flash',
            'tesla' => 'deepseek-v4-pro',
            // Fast & light — conversational & growth, plus the scribe
            'sophia' => 'claude-haiku-4-5-20251001',
            'kira' => 'gpt-5.4-mini',
            'scribe' => 'claude-haiku-4-5-20251001',
            // Light tier — non-expert client & playful game designer
            'dragan' => 'gpt-5.4-nano',
            'pixel' => 'mistral-small-latest',
            // Realist — feasibility judgment; Chair — final decision
            'realist' => 'claude-sonnet-4-6',
            'chair' => 'claude-opus-5',
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
