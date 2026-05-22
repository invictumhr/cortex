<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use App\Models\Chat;
use App\Models\Persona;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\CostEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public API endpoint: kick off a boardroom discussion, return the chat row.
 * Authenticated via api.token middleware (cortex:discuss scope required).
 *
 * The chat runs through the same orchestrator as the CLI/web — wallet
 * reserves/debits happen per persona inside PersonaResponder.
 *
 * POST /api/v1/discuss
 *   { "topic": "...", "personas": ["marco","luna"], "rounds": 2, "title": "..." }
 */
class DiscussController extends Controller
{
    public function __invoke(
        Request $request,
        ChatOrchestrator $orchestrator,
        CostEstimator $estimator,
    ): JsonResponse {
        // V1: run the boardroom synchronously so callers get the full result
        // in the response. Async + webhook can come in V2 — for now this
        // mirrors the CLI behavior. Same broadcasting trick: drop Reverb so
        // the loop doesn't try to hit a WebSocket server during an HTTP call.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $started = microtime(true);
        $token = $request->attributes->get('api_token');
        \assert($token instanceof ApiToken);
        $user = $token->user;

        $validated = $request->validate([
            'topic' => 'required|string|min:3|max:5000',
            'personas' => 'array',
            'personas.*' => 'string',
            'rounds' => 'integer|min:1|max:'.config('cortex.max_rounds', 200),
            'title' => 'nullable|string|max:255',
            'context' => 'nullable|string|max:50000',
            'constraints' => 'nullable|string|max:5000',
        ]);

        $rounds = (int) ($validated['rounds'] ?? 2);
        $slugs = array_filter(array_map('trim', (array) ($validated['personas'] ?? [])));

        // Default panel = first 5 non-scribe/chair personas if user didn't pick;
        // a fuller router runs in cortex:discuss but the API stays predictable.
        $personas = $slugs !== []
            ? Persona::whereIn('slug', $slugs)->where('is_scribe', false)->where('is_chair', false)->get()
            : Persona::where('is_scribe', false)->where('is_chair', false)->where('is_ephemeral', false)
                ->orderBy('sort_order')->limit(5)->get();

        if ($personas->isEmpty()) {
            return $this->logAndReturn($token, null, ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'no_personas_resolved'], 422));
        }

        try {
            $chat = $user->chats()->create([
                'initiated_by_token_id' => $token->id,
                'title' => $validated['title'] ?? 'API discussion',
                'context' => $validated['context'] ?? null,
                'constraints' => $validated['constraints'] ?? null,
                'rounds_per_turn' => $rounds,
                'scribe_interval' => (int) config('cortex.default_scribe_interval', 50),
                'language' => 'English',
                'status' => Chat::STATUS_ACTIVE,
            ]);

            foreach ($personas as $persona) {
                $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);
            }

            $orchestrator->sendUserMessage($chat, $user, $validated['topic']);
        } catch (InsufficientFundsException $e) {
            return $this->logAndReturn($token, null, ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($e->available, 6),
                    'requested_eur' => round($e->requested, 6),
                ], 402));
        } catch (Throwable $e) {
            report($e);

            return $this->logAndReturn($token, null, ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => $e->getMessage()], 500));
        }

        $chat->refresh();

        // Per-call usage log so the user dashboard can show "this token cost
        // me €X today". user_cost reads off the chat's debit roll-up.
        $userCost = (float) $chat->messages()->sum('user_cost');
        $providerCost = (float) $chat->messages()->sum('provider_cost');

        return $this->logAndReturn(
            $token,
            $chat->id,
            ApiTokenUsage::STATUS_OK,
            $started,
            response()->json([
                'ok' => true,
                'chat_id' => $chat->id,
                'status' => $chat->status,
                'rounds' => (int) $chat->rounds_per_turn,
                'messages' => $chat->messages()->where('id', '>', 0)->orderBy('id')->get([
                    'id', 'role', 'persona_id', 'round_number', 'content',
                    'is_billable', 'provider_cost', 'user_cost', 'model_used',
                ]),
                'total_provider_cost_eur' => round($providerCost, 6),
                'total_user_cost_eur' => round($userCost, 6),
            ]),
            providerCost: $providerCost,
            userCost: $userCost,
        );
    }

    /**
     * Wrap the response in an api_token_usages log row, then return it.
     */
    private function logAndReturn(
        ApiToken $token,
        ?int $chatId,
        string $status,
        float $started,
        JsonResponse $response,
        float $providerCost = 0,
        float $userCost = 0,
    ): JsonResponse {
        ApiTokenUsage::create([
            'api_token_id' => $token->id,
            'chat_id' => $chatId,
            'endpoint' => 'POST /api/v1/discuss',
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'response_time_ms' => (int) ((microtime(true) - $started) * 1000),
            'status' => $status,
            'created_at' => now(),
        ]);

        return $response;
    }
}
