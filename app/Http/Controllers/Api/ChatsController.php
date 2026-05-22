<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientFundsException;
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
 */
class ChatsController extends Controller
{
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

        $validated = $request->validate([
            'before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = $user->chats()->orderByDesc('updated_at');

        if (empty($validated['include_archived'])) {
            $query->where('status', '!=', Chat::STATUS_ARCHIVED);
        }

        if (! empty($validated['before'])) {
            $query->where('updated_at', '<', $validated['before']);
        }

        $chats = $query->limit($limit)->get([
            'id', 'title', 'status', 'init_mode', 'language',
            'total_messages', 'total_cost', 'current_round',
            'continuous', 'created_at', 'updated_at',
        ]);

        return $this->logUsage($token, 'GET /api/v1/chats', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'chats' => $chats,
                'next_before' => $chats->last()?->updated_at?->toIso8601String(),
            ])
        );
    }

    /**
     * GET /api/v1/chats/{chat}
     *
     * Single chat with its messages, scribe summaries, and personas.
     * `?messages_after=ID` returns only newer messages for streaming clients
     * polling for updates without re-downloading the whole transcript.
     */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'GET /api/v1/chats/{id}', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chat->id);
        }

        $validated = $request->validate([
            'messages_after' => ['nullable', 'integer', 'min:0'],
        ]);

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
                'chat' => $chat->only([
                    'id', 'title', 'description', 'context', 'constraints',
                    'status', 'init_mode', 'language',
                    'current_round', 'rounds_per_turn', 'continuous',
                    'total_messages', 'total_input_tokens', 'total_output_tokens',
                    'total_cost', 'created_at', 'updated_at',
                ]),
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
     * POST /api/v1/chats/{chat}/messages
     *
     * Drop a follow-up message into an existing chat. Forces sync queue +
     * log broadcasting (same trick as DiscussController) so the whole turn
     * runs inside this HTTP call and we return the new messages in the body.
     */
    public function storeMessage(Request $request, Chat $chat): JsonResponse
    {
        // Sync queue → the orchestrator chain runs inline and we wait for it.
        // Reverb is replaced with the log driver so we don't try to push to a
        // websocket from inside an HTTP request.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $started = microtime(true);
        $token = $this->token($request);
        $user = $token->user;

        if (! $this->ownsChat($token, $chat)) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => 'forbidden'], 403), chatId: $chat->id);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:20000'],
            'language' => ['nullable', 'string', 'in:'.implode(',', LanguageDetector::supportedIsoCodes())],
            // Optional per-turn override. Default keeps whatever the chat already has.
            'rounds' => ['nullable', 'integer', 'min:1', 'max:'.config('cortex.max_rounds', 200)],
        ]);

        if (! empty($validated['language'])) {
            $resolved = LanguageDetector::fromIso($validated['language']);
            if ($resolved && $resolved !== $chat->language) {
                $chat->update(['language' => $resolved]);
            }
        }

        if (! empty($validated['rounds'])) {
            $chat->update(['rounds_per_turn' => (int) $validated['rounds']]);
        }

        // Pre-flight balance gate — same logic as the web controller. We don't
        // estimate the whole turn, just refuse if the user has no spendable
        // euros at all so the orchestrator doesn't get partway and bail.
        $wallet = $this->wallets->forUser($user);
        $minFloor = (float) config('cortex.billing.min_send_balance', 0.05);
        if ($wallet->availableBalance() < $minFloor) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($wallet->availableBalance(), 6),
                    'min_send_eur' => $minFloor,
                ], 402),
                chatId: $chat->id,
            );
        }

        $lastIdBefore = (int) $chat->messages()->max('id');

        try {
            $userMessage = $this->orchestrator->sendUserMessage($chat, $user, $validated['content']);
        } catch (InsufficientFundsException $e) {
            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_INSUFFICIENT_FUNDS, $started,
                response()->json([
                    'error' => 'insufficient_funds',
                    'available_eur' => round($e->available, 6),
                    'requested_eur' => round($e->requested, 6),
                ], 402),
                chatId: $chat->id,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_ERROR, $started,
                response()->json(['error' => $e->getMessage()], 500),
                chatId: $chat->id,
            );
        }

        $chat->refresh();

        // Roll up just the messages produced by THIS turn (id > lastIdBefore)
        // so the caller sees only the new traffic and the usage row reflects
        // the cost of this specific call rather than the chat lifetime.
        $newMessages = $chat->messages()
            ->where('id', '>', $lastIdBefore)
            ->orderBy('id')
            ->get([
                'id', 'persona_id', 'role', 'round_number', 'content',
                'is_billable', 'provider_cost', 'user_cost', 'model_used',
                'finish_reason', 'created_at',
            ]);

        $providerCost = (float) $newMessages->sum('provider_cost');
        $userCost = (float) $newMessages->sum('user_cost');

        return $this->logUsage($token, 'POST /api/v1/chats/{id}/messages', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'ok' => true,
                'chat_id' => $chat->id,
                'status' => $chat->status,
                'user_message_id' => $userMessage->id,
                'messages' => $newMessages,
                'turn_provider_cost_eur' => round($providerCost, 6),
                'turn_user_cost_eur' => round($userCost, 6),
            ]),
            chatId: $chat->id,
            chatMessageId: $userMessage->id,
            providerCost: $providerCost,
            userCost: $userCost,
        );
    }

    /**
     * POST /api/v1/chats/{chat}/archive
     *
     * Soft-archive: status → archived, no data loss. Mirrors the web archive
     * route so a client can hide chats from the active list without losing
     * the transcript.
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
     * DELETE /api/v1/chats/{chat}
     *
     * Hard delete with FK cascade — messages, scribe summaries, attachments
     * and pivot rows all go. This is destructive; clients should confirm
     * before calling it.
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

        $chat->delete();

        // chat_id on the usage row would FK-fail if we passed the now-deleted
        // id, so we leave it null here and let the endpoint string identify
        // what was hit. The deleted_chat_id sits in metadata via response only.
        return $this->logUsage($token, 'DELETE /api/v1/chats/{id}', ApiTokenUsage::STATUS_OK, $started,
            response()->json(['ok' => true, 'deleted_chat_id' => $chatId]),
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
