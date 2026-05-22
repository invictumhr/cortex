<?php

namespace App\Services\Chat;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ChatPersona;
use App\Models\Persona;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Compose a panel on first-message arrival for chats that were created
 * without one (init_mode = `quick` or `custom_models`).
 *
 *   quick           — user picked head-count only. Composer picks models
 *                     (tiered by topic complexity), then asks the architect
 *                     to invent N orthogonal roles, then attaches each
 *                     persona with its assigned model in the pivot.
 *
 *   custom_models   — user picked exact models. Count of personas = count
 *                     of models. Architect invents N roles for the topic;
 *                     i-th role gets the i-th model.
 *
 *   custom          — user pre-attached (persona, model) pairs. Composer is
 *                     a no-op for these chats — panel was set at creation.
 *
 * Composer is **idempotent** by `chat.panel_composed` — once true, never
 * runs again for that chat.
 */
class BoardroomComposer
{
    public function __construct(private PanelArchitect $architect) {}

    /**
     * Compose if needed. Returns true when a panel was attached, false when
     * nothing needed doing (already composed, or custom mode).
     */
    public function composeIfNeeded(Chat $chat, string $firstMessage): bool
    {
        if ($chat->panel_composed) {
            return false;
        }

        $mode = (string) $chat->init_mode;

        if ($mode === Chat::INIT_CUSTOM) {
            // Personas already attached at creation; nothing to compose.
            $chat->update(['panel_composed' => true]);

            return false;
        }

        $personas = match ($mode) {
            Chat::INIT_QUICK         => $this->composeQuick($chat, $firstMessage),
            Chat::INIT_CUSTOM_MODELS => $this->composeCustomModels($chat, $firstMessage),
            default                  => collect(),
        };

        if ($personas->isEmpty()) {
            Log::warning('[boardroom-composer] empty result', [
                'chat_id' => $chat->id, 'mode' => $mode,
            ]);

            return false;
        }

        $chat->update(['panel_composed' => true]);

        return true;
    }

    /**
     * Quick mode: head-count from panel_config.agents (default 5), tier from
     * topic complexity, pick distinct models, generate matching personas.
     *
     * @return Collection<int, Persona>
     */
    private function composeQuick(Chat $chat, string $firstMessage): Collection
    {
        $config = (array) ($chat->panel_config ?? []);
        $count = max(2, min(8, (int) ($config['agents'] ?? 5)));

        $tier = $this->pickTierFor($firstMessage, $chat);
        $models = $this->pickDistinctModels($tier, $count);

        if ($models->count() < $count) {
            // Pool too small even with fallback — degrade gracefully and
            // run with what we have rather than blocking the chat.
            $count = max(2, $models->count());
        }

        $personas = $this->architect->design($chat, $firstMessage, $count, $models);

        return $this->attach($chat, $personas, $models);
    }

    /**
     * Custom-models mode: user already picked the exact models (panel_config.models
     * = array of AI model IDs). Architect invents one persona per model.
     *
     * @return Collection<int, Persona>
     */
    private function composeCustomModels(Chat $chat, string $firstMessage): Collection
    {
        $config = (array) ($chat->panel_config ?? []);
        $modelIds = array_values(array_filter(array_map('intval', (array) ($config['models'] ?? []))));

        if ($modelIds === []) {
            return collect();
        }

        // Preserve user-given order so the i-th persona aligns with the
        // i-th model the user picked. De-dupe defensively.
        $modelIds = array_values(array_unique($modelIds));

        $models = AiModel::query()
            ->whereIn('id', $modelIds)
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('provider')
            ->get()
            ->sortBy(fn ($m) => array_search($m->id, $modelIds, true))
            ->values();

        if ($models->isEmpty()) {
            return collect();
        }

        $personas = $this->architect->design($chat, $firstMessage, $models->count(), $models);

        return $this->attach($chat, $personas, $models);
    }

    /**
     * Lightweight topic-complexity heuristic. No extra API call.
     *
     *   small    — short message, no keywords ("quick question"-shaped)
     *   medium   — default
     *   flagship — long brief or strong-mode flag explicitly set
     */
    private function pickTierFor(string $message, Chat $chat): string
    {
        if ($chat->strong) {
            return 'flagship';
        }

        $trimmed = trim($message);
        $len = mb_strlen($trimmed);

        $heavyKeywords = '/(strategy|architecture|design|plan|evaluate|compare|migration|refactor|trade-?off|strategi|arhitektur|migrac|refaktor)/i';
        $looksHeavy = $len > 500 || preg_match($heavyKeywords, $trimmed);

        if ($looksHeavy) {
            return 'flagship';
        }
        if ($len < 100) {
            return 'small';
        }

        return 'medium';
    }

    /**
     * Pick N distinct models from the chosen tier. If the tier doesn't have
     * enough, top up from adjacent tiers ('flagship' ↑ 'medium' ↑ 'small').
     * Distinct = unique `model_string`, NOT unique provider — user explicitly
     * wanted "nikad 2 ista modela", not "different providers".
     *
     * @return Collection<int, AiModel>
     */
    private function pickDistinctModels(string $tier, int $count): Collection
    {
        $tiers = (array) config('cortex.model_tiers', []);
        $order = match ($tier) {
            'small'    => ['small', 'medium', 'flagship'],
            'flagship' => ['flagship', 'medium', 'small'],
            default    => ['medium', 'flagship', 'small'],
        };

        $picked = collect();
        $seenStrings = [];

        foreach ($order as $tierName) {
            $strings = (array) ($tiers[$tierName] ?? []);
            shuffle($strings); // randomise within a tier so panels vary
            foreach ($strings as $modelString) {
                if (in_array($modelString, $seenStrings, true)) {
                    continue;
                }
                $model = AiModel::query()
                    ->where('model_string', $modelString)
                    ->where('is_active', true)
                    ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
                    ->with('provider')
                    ->first();
                if ($model) {
                    $picked->push($model);
                    $seenStrings[] = $modelString;
                    if ($picked->count() >= $count) {
                        return $picked;
                    }
                }
            }
        }

        return $picked;
    }

    /**
     * Attach generated personas to the chat with their assigned model in the
     * pivot's `ai_model_id`. PersonaResponder::modelFor reads pivot first.
     *
     * @param  Collection<int, Persona>  $personas
     * @param  Collection<int, AiModel>  $models
     * @return Collection<int, Persona>
     */
    private function attach(Chat $chat, Collection $personas, Collection $models): Collection
    {
        $personas->values()->each(function (Persona $persona, int $i) use ($chat, $models) {
            $model = $models->get($i);
            ChatPersona::create([
                'chat_id' => $chat->id,
                'persona_id' => $persona->id,
                'ai_model_id' => $model?->id,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        });

        return $personas;
    }
}
