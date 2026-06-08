<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Api\Concerns\LogsApiUsage;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use App\Models\Chat;
use App\Models\Persona;
use App\Services\Billing\WalletService;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\CostEstimator;
use App\Services\Chat\TitleGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public API endpoint: kick off a boardroom discussion asynchronously.
 * Returns 202 Accepted with the chat's public_id immediately — the caller
 * polls GET /api/v1/chats/{public_id} for progress and results.
 *
 * POST /api/v1/discuss
 *   { "topic": "...", "personas": ["marco","luna"], "rounds": 2, "title": "..." }
 */
class DiscussController extends Controller
{
    use LogsApiUsage;

    public function __invoke(
        Request $request,
        ChatOrchestrator $orchestrator,
        CostEstimator $estimator,
        WalletService $wallets,
        TitleGenerator $titleGenerator,
    ): JsonResponse {
        $started = microtime(true);
        $token = $request->attributes->get('api_token');
        \assert($token instanceof ApiToken);
        $user = $token->user;

        $validated = $request->validate([
            'topic' => 'required|string|min:3|max:5000',
            // Three mutually-exclusive panel-init shapes — caller picks one.
            // Mode 1 (quick): just say how many agents you want; system picks models.
            'agents' => 'nullable|integer|min:2|max:8',
            // Mode 2 (custom_models): explicit model strings; one persona per model.
            'models' => 'nullable|array',
            'models.*' => 'string',
            // Mode 3 (custom): explicit (persona_slug, model_string) pairs.
            // Same persona can repeat with a different model.
            'panel' => 'nullable|array',
            'panel.*.persona' => 'required_with:panel|string',
            'panel.*.model' => 'nullable|string',
            // Legacy — kept so old callers don't break. Maps to a custom-mode
            // run with each persona on its default model.
            'personas' => 'nullable|array',
            'personas.*' => 'string',
            'rounds' => 'integer|min:1|max:'.config('cortex.max_rounds', 200),
            'title' => 'nullable|string|max:255',
            'context' => 'nullable|string|max:50000',
            'constraints' => 'nullable|string|max:5000',
            'language' => 'nullable|string|size:2',
        ]);

        $rounds = (int) ($validated['rounds'] ?? 2);

        // Pre-flight balance check — refuse immediately if the wallet is
        // below the minimum so we don't create an empty chat and then fail
        // inside the queued job where the caller can't see the error.
        $wallet = $wallets->forUser($user);
        $minFloor = (float) config('cortex.billing.min_send_balance', 0.05);
        if ($wallet->availableBalance() < $minFloor) {
            return $this->logUsage($token, 'POST /api/v1/discuss', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($wallet->availableBalance(), 6),
                    'min_send_eur' => $minFloor,
                ], 402));
        }

        // Resolve init mode from the request body — same precedence the CLI
        // uses: panel > models > agents > personas > default quick.
        [$initMode, $panelConfig] = $this->resolveInitMode($validated);

        // Auto-generate title from topic when the caller didn't provide one.
        $title = $validated['title'] ?? null;
        if (empty($title)) {
            $title = $titleGenerator->generate($validated['topic'], 'API');
        }

        try {
            $chat = $user->chats()->create([
                'initiated_by_token_id' => $token->id,
                'title' => $title ?: 'API discussion',
                'context' => $validated['context'] ?? null,
                'constraints' => $validated['constraints'] ?? null,
                'init_mode' => $initMode,
                'panel_config' => $panelConfig,
                'panel_composed' => $initMode === Chat::INIT_CUSTOM,
                'rounds_per_turn' => $rounds,
                'scribe_interval' => (int) config('cortex.default_scribe_interval', 50),
                'language' => $this->resolveLanguage($validated),
                'status' => Chat::STATUS_ACTIVE,
            ]);

            if ($initMode === Chat::INIT_CUSTOM) {
                $this->attachCustomPanel($chat, $panelConfig);
                if ($chat->personas()->count() === 0) {
                    return $this->logUsage($token, 'POST /api/v1/discuss', ApiTokenUsage::STATUS_ERROR, $started,
                        response()->json(['error' => 'no_personas_resolved'], 422));
                }
            }

            // Dispatch the discussion to the real queue — returns immediately.
            // The queue worker processes persona responses, scribe, and chair
            // in the background. The caller polls GET /chats/{public_id} for
            // progress (status flips to 'paused' when the turn is done).
            $orchestrator->sendUserMessage($chat, $user, $validated['topic']);
        } catch (InsufficientFundsException $e) {
            return $this->logUsage($token, 'POST /api/v1/discuss', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($e->available, 6),
                    'requested_eur' => round($e->requested, 6),
                ], 402));
        } catch (Throwable $e) {
            report($e);

            return $this->logUsage($token, 'POST /api/v1/discuss', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => $e->getMessage()], 500));
        }

        return $this->logUsage($token, 'POST /api/v1/discuss', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'ok' => true,
                'chat_id' => $chat->public_id,
                'status' => $chat->status,
                'title' => $chat->title,
                'rounds' => (int) $chat->rounds_per_turn,
                'poll_url' => '/api/v1/chats/'.$chat->public_id,
            ], 202),
            chatId: $chat->id,
        );
    }

    /**
     * Map the request body to (init_mode, panel_config). Same precedence as
     * CortexDiscuss CLI: panel > models > agents > personas (legacy) > default.
     *
     * @param  array<string,mixed>  $v
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function resolveInitMode(array $v): array
    {
        if (! empty($v['panel'])) {
            $pairs = [];
            foreach ($v['panel'] as $row) {
                $slug = trim((string) ($row['persona'] ?? ''));
                $model = trim((string) ($row['model'] ?? ''));
                if ($slug !== '') {
                    $pairs[] = ['persona_slug' => $slug, 'model_string' => $model !== '' ? $model : null];
                }
            }

            return [Chat::INIT_CUSTOM, ['pairs' => $pairs]];
        }

        if (! empty($v['models'])) {
            $ids = AiModel::query()
                ->whereIn('model_string', $v['models'])
                ->where('is_active', true)
                ->pluck('id')->all();

            return [Chat::INIT_CUSTOM_MODELS, ['models' => array_values($ids)]];
        }

        if (! empty($v['agents'])) {
            return [Chat::INIT_QUICK, ['agents' => max(2, min(8, (int) $v['agents']))]];
        }

        if (! empty($v['personas'])) {
            $pairs = [];
            foreach ($v['personas'] as $slug) {
                $pairs[] = ['persona_slug' => $slug, 'model_string' => null];
            }

            return [Chat::INIT_CUSTOM, ['pairs' => $pairs]];
        }

        // Default for the API: quick mode with 5 agents (same as plain CLI).
        return [Chat::INIT_QUICK, ['agents' => 5]];
    }

    private function resolveLanguage(array $v): string
    {
        $iso = strtolower(trim((string) ($v['language'] ?? 'en')));
        $supported = \App\Services\LanguageDetector::supportedIsoCodes();
        $iso = in_array($iso, $supported, true) ? $iso : 'en';

        return \App\Services\LanguageDetector::fromIso($iso);
    }

    /**
     * Custom mode only — attach (persona_slug, model_string) pairs to the
     * pivot at creation. quick / custom_models compose after first message.
     *
     * @param  array<string,mixed>  $panelConfig
     */
    private function attachCustomPanel(Chat $chat, array $panelConfig): void
    {
        foreach ((array) ($panelConfig['pairs'] ?? []) as $pair) {
            $persona = Persona::query()
                ->where('slug', $pair['persona_slug'] ?? '')
                ->where('is_scribe', false)->where('is_chair', false)
                ->first();
            if (! $persona) continue;

            $modelId = null;
            if (! empty($pair['model_string'])) {
                $model = AiModel::query()
                    ->where('model_string', $pair['model_string'])
                    ->where('is_active', true)
                    ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
                    ->first();
                $modelId = $model?->id;
            }

            $chat->personas()->attach($persona->id, [
                'ai_model_id' => $modelId,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }
    }
}
