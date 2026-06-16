<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Api\Concerns\HandlesIdempotency;
use App\Http\Controllers\Api\Concerns\LogsApiUsage;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use App\Models\Chat;
use App\Services\Billing\WalletService;
use App\Services\Chat\ChatOrchestrator;
use App\Services\LanguageDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * REST endpoints for chats — list, read, archive, delete, and send follow-up
 * messages. Mirrors the web ChatController/ChatMessageController contracts
 * but is JSON-only and bills off the token owner's wallet.
 *
 * All routes are scoped to the token user (no cross-user visibility) and
 * write a row to api_token_usages per request via LogsApiUsage.
 *
 * Routes bind by {chat:public_id} — sequential numeric IDs are never exposed.
 */
class ChatsController extends Controller
{
    use HandlesIdempotency;
    use LogsApiUsage;

    public function __construct(
        private ChatOrchestrator $orchestrator,
        private WalletService $wallets,
    ) {}

    /**
     * GET /api/v1/chats
     *
     * List the caller's non-archived chats, newest first. Pagination is
     * cursor-style on `updated_at` — clients pass `?before=ISO-8601` to fetch
     * the next page. `?include_archived=1` flips the filter for cleanup tools.
     */
    public function index(Request $request): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);
        $user = $token->user;

        $validated = $this->validateLogged($request, [
            'before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_archived' => ['nullable', 'boolean'],
        ], $token, 'GET /api/v1/chats', $started);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = $user->chats()->orderByDesc('updated_at');

        if (empty($validated['include_archived'])) {
            $query->where('status', '!=', Chat::STATUS_ARCHIVED);
        }

        if (! empty($validated['before'])) {
            $query->where('updated_at', '<', $validated['before']);
        }

        // Fetch one extra row so the response can say whether another page
        // exists without the client having to probe blindly.
        $chats = $query->limit($limit + 1)->get([
            'id', 'public_id', 'title', 'status', 'init_mode', 'language',
            'total_messages', 'total_cost', 'current_round',
            'continuous', 'created_at', 'updated_at',
        ]);

        $hasMore = $chats->count() > $limit;
        $chats = $chats->take($limit);

        // Map public_id to the "id" field in the response — callers never see
        // the numeric id.
        $mapped = $chats->map(fn (Chat $c) => [
            'id' => $c->public_id,
            'title' => $c->title,
            'status' => $c->status,
            'init_mode' => $c->init_mode,
            'language' => $c->language,
            'total_messages' => $c->total_messages,
            'total_cost' => $c->total_cost,
            'current_round' => $c->current_round,
            'continuous' => $c->continuous,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ]);

        return $this->logUsage($token, 'GET /api/v1/chats', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'chats' => $mapped,
                'has_more' => $hasMore,
                'next_before' => $hasMore ? $chats->last()?->updated_at?->toIso8601String() : null,
            ])
        );
    }

    /**
     * GET /api/v1/chats/{chat:public_id}
     *
     * Single chat with its messages, scribe summaries, and personas.
     * `?messages_after=ID` returns only newer messages for polling clients
     * without re-downloading the whole transcript.
     *
     * Polling pattern: call this endpoint until `chat.status` flips from
     * "active" to "paused" — that means the turn is done.
     */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'GET /api/v1/chats/{id}', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chat->id);
        }

        $validated = $this->validateLogged($request, [
            'messages_after' => ['nullable', 'integer', 'min:0'],
        ], $token, 'GET /api/v1/chats/{id}', $started, chatId: $chat->id);

        $messagesQ = $chat->messages()
            ->with(['persona:id,slug,name,title,avatar_emoji,avatar_color,is_scribe'])
            ->orderBy('id');

        if (! empty($validated['messages_after'])) {
            $messagesQ->where('id', '>', (int) $validated['messages_after']);
        }

        $messages = $messagesQ->get([
            'id', 'chat_id', 'persona_id', 'role', 'content',
            'round_number', 'turn_number', 'model_used',
            'is_billable', 'provider_cost', 'user_cost', 'finish_reason',
            'created_at',
        ]);

        return $this->logUsage($token, 'GET /api/v1/chats/{id}', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'chat' => [
                    'id' => $chat->public_id,
                    'title' => $chat->title,
                    'description' => $chat->description,
                    'context' => $chat->context,
                    'constraints' => $chat->constraints,
                    'status' => $chat->status,
                    'init_mode' => $chat->init_mode,
                    'language' => $chat->language,
                    'current_round' => $chat->current_round,
                    'rounds_per_turn' => $chat->rounds_per_turn,
                    'continuous' => $chat->continuous,
                    'total_messages' => $chat->total_messages,
                    'total_input_tokens' => $chat->total_input_tokens,
                    'total_output_tokens' => $chat->total_output_tokens,
                    'total_cost' => $chat->total_cost,
                    'created_at' => $chat->created_at,
                    'updated_at' => $chat->updated_at,
                ],
                'personas' => $chat->personas()
                    ->with('aiModel:id,name,model_string')
                    ->get(['personas.id', 'slug', 'name', 'title', 'avatar_emoji', 'avatar_color', 'is_scribe', 'is_chair']),
                'messages' => $messages,
                'scribe_summaries' => $chat->scribeSummaries()->orderBy('id')->get(),
            ]),
            chatId: $chat->id,
        );
    }

    /**
     * POST /api/v1/chats/{chat:public_id}/messages
     *
     * Drop a follow-up message into an existing chat. The discussion runs
     * asynchronously on the queue — returns 202 immediately. Poll
     * GET /chats/{public_id} for the resulting messages.
     */
    public function storeMessage(Request $request, Chat $chat): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);
        $user = $token->user;

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chat->id);
        }

        $validated = $this->validateLogged($request, [
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'language' => ['nullable', 'string', 'in:'.implode(',', LanguageDetector::supportedIsoCodes())],
            // Optional per-turn override. Default keeps whatever the chat already has.
            'rounds' => ['nullable', 'integer', 'min:1', 'max:'.config('cortex.max_rounds', 200)],
        ], $token, 'POST /api/v1/chats/{id}/messages', $started, chatId: $chat->id);

        // A bounded (non-continuous) chat that is still ACTIVE has a persona
        // chain mid-flight — restarting the turn now would reset the round
        // counter under the running chain and double-bill the round. Tell the
        // caller to poll until the turn finishes.
        if (! $chat->continuous && $chat->status === Chat::STATUS_ACTIVE) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json([
                    'error' => 'discussion_running',
                    'message' => 'The previous turn is still running. Poll the chat until status flips to "paused", then retry.',
                    'poll_url' => '/api/v1/chats/'.$chat->public_id,
                ], 409),
                chatId: $chat->id,
            );
        }

        // Optional Idempotency-Key header — a network-retry duplicate replays
        // the original 202 instead of starting (and billing) a second turn.
        $idemKey = $this->idempotencyKey($request, $token);
        if ($replay = $this->beginIdempotent($idemKey)) {
            return $replay;
        }

        if (! empty($validated['language'])) {
            $resolved = LanguageDetector::fromIso($validated['language']);
            if ($resolved && $resolved !== $chat->language) {
                $chat->update(['language' => $resolved]);
            }
        }

        if (! empty($validated['rounds'])) {
            $chat->update(['rounds_per_turn' => (int) $validated['rounds']]);
        }

        // Pre-flight balance gate — refuse if the user has no spendable euros
        // at all so the orchestrator doesn't get partway and bail.
        $wallet = $this->wallets->forUser($user);
        $minFloor = (float) config('cortex.billing.min_send_balance', 0.05);
        if ($wallet->availableBalance() < $minFloor) {
            return $this->finishIdempotent($idemKey, $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($wallet->availableBalance(), 6),
                    'min_send_eur' => $minFloor,
                ], 402),
                chatId: $chat->id,
            ));
        }

        try {
            // Dispatch to the real queue — returns immediately after creating
            // the user message and kicking off the first persona job.
            $userMessage = $this->orchestrator->sendUserMessage($chat, $user, $validated['content']);
        } catch (InsufficientFundsException $e) {
            return $this->finishIdempotent($idemKey, $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($e->available, 6),
                    'requested_eur' => round($e->requested, 6),
                ], 402),
                chatId: $chat->id,
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->finishIdempotent($idemKey, $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => $e->getMessage()], 500),
                chatId: $chat->id,
            ));
        }

        return $this->finishIdempotent($idemKey, $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'ok' => true,
                'chat_id' => $chat->public_id,
                'status' => $chat->status,
                'user_message_id' => $userMessage->id,
                'poll_url' => '/api/v1/chats/'.$chat->public_id,
            ], 202),
            chatId: $chat->id,
            chatMessageId: $userMessage->id,
        ));
    }

    /**
     * POST /api/v1/chats/{chat:public_id}/archive
     */
    public function archive(Request $request, Chat $chat): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/archive', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chat->id);
        }

        $chat->update(['status' => Chat::STATUS_ARCHIVED]);

        return $this->logUsage($token, 'POST /api/v1/chats/{id}/archive', ApiTokenUsage::STATUS_OK, $started,
            response()->json(['ok' => true, 'status' => $chat->status]),
            chatId: $chat->id,
        );
    }

    /**
     * DELETE /api/v1/chats/{chat:public_id}
     */
    public function destroy(Request $request, Chat $chat): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);
        $chatId = $chat->id;

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'DELETE /api/v1/chats/{id}', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chatId);
        }

        $publicId = $chat->public_id;
        $chat->delete();

        return $this->logUsage($token, 'DELETE /api/v1/chats/{id}', ApiTokenUsage::STATUS_OK, $started,
            response()->json(['ok' => true, 'deleted_chat_id' => $publicId]),
        );
    }

    /**
     * Extract the ApiToken stamped on the request by AuthenticateApiToken
     * middleware. Throws if missing (would indicate a route wiring bug).
     */
    private function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('api_token');
        \assert($token instanceof ApiToken);

        return $token;
    }

    private function ownsChat(ApiToken $token, Chat $chat): bool
    {
        return $chat->user_id === $token->user_id;
    }
}
