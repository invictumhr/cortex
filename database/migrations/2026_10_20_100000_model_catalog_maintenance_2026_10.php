<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * October 2026 catalog maintenance. Two jobs:
 *
 *  1. STRUCTURAL — add `deprecated_at` / `retires_at` to ai_models so model
 *     end-of-life stops living only in code comments. `cortex:model-health`
 *     reads these to warn (and fail) before a model 404s under production
 *     traffic. Previous retirements (gemini-2.5, grok-3, o3…) were all handled
 *     by hand after someone re-read CLAUDE.md — this makes it an alert.
 *
 *  2. DATA — backfill known EOL dates, and deactivate o3 now (API shutdown
 *     2026-12-11, ~7 weeks out; nothing uses it since Ana moved to gpt-5.6-sol,
 *     so retire it early rather than let an API/admin pick a model that is
 *     about to 404).
 *
 * Keep in sync with AiModelSeeder (new columns + o3 inactive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_models', 'deprecated_at')) {
                $table->date('deprecated_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('ai_models', 'retires_at')) {
                $table->date('retires_at')->nullable()->after('deprecated_at');
            }
        });

        // model_string => [deprecated_at, retires_at]. Dates from each
        // provider's published deprecation schedule (verified 2026-10-20).
        $lifecycle = [
            // OpenAI — o3 API shutdown; gpt-4o* legacy track flagged for exit
            // but OpenAI has not published a firm API retirement date, so only
            // deprecated_at is set (no fabricated retires_at).
            'o3' => ['2026-06-01', '2026-12-11'],
            'gpt-4o-mini' => ['2026-07-29', null],
            'gpt-4o' => ['2026-07-29', null],
            'gpt-4.1' => ['2026-07-29', null],
            // Anthropic legacy
            'claude-opus-4-20250514' => ['2026-03-01', '2026-06-15'],
            'claude-sonnet-4-20250514' => ['2026-03-01', '2026-06-15'],
            // xAI
            'grok-3' => ['2026-03-15', '2026-05-15'],
            // Google — Gemini 2.5 family
            'gemini-2.5-pro' => ['2026-07-29', '2026-10-16'],
            'gemini-2.5-flash' => ['2026-07-29', '2026-10-16'],
            'gemini-2.5-flash-lite' => ['2026-07-29', '2026-10-16'],
            // DeepSeek
            'deepseek-chat' => ['2026-06-01', '2026-07-24'],
        ];

        foreach ($lifecycle as $modelString => [$deprecatedAt, $retiresAt]) {
            DB::table('ai_models')
                ->where('model_string', $modelString)
                ->update([
                    'deprecated_at' => $deprecatedAt,
                    'retires_at' => $retiresAt,
                    'updated_at' => now(),
                ]);
        }

        // Retire o3 early — it is deprecated, unused by any persona, and 404s
        // on 2026-12-11. Any per-chat pivot still pinned to it falls through to
        // the persona default at runtime (PersonaResponder requires is_active).
        DB::table('ai_models')
            ->where('model_string', 'o3')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn(['deprecated_at', 'retires_at']);
        });

        // Re-activating o3 on rollback is intentional (restores pre-migration
        // state); the catalog refresh that seeded it active is untouched.
        DB::table('ai_models')
            ->where('model_string', 'o3')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
