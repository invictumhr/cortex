<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Services\Billing\WalletService;
use App\Services\Chat\ChatOrchestrator;
use App\Services\LanguageDetector;
use App\Services\UrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ChatMessageController extends Controller
{
    public function __construct(
        private ChatOrchestrator $orchestrator,
        private WalletService $wallets,
    ) {}

    /**
     * JSON message feed for the chat page. Two access patterns:
     *   ?after_id=N  — incremental fetch (Echo reconnect catch-up), ascending
     *   ?before_id=N — older history page ("Load earlier"), ascending slice
     *                  ending just before N
     * Without parameters returns the newest page.
     */
    public function index(Request $request, Chat $chat): JsonResponse
    {
        Gate::authorize('view', $chat);

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);

        $query = $chat->messages()
            ->with(['persona:id,name,title,avatar_emoji,avatar_color,is_scribe', 'attachments']);

        if (isset($validated['after_id'])) {
            $rows = $query->where('id', '>', (int) $validated['after_id'])
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();

            $hasMore = $rows->count() > $limit;

            return response()->json([
                'messages' => $rows->take($limit)->values(),
                'has_more' => $hasMore,
            ]);
        }

        if (! empty($validated['before_id'])) {
            $query->where('id', '<', (int) $validated['before_id']);
        }

        // Newest page first, then flip to ascending for rendering.
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return response()->json([
            'messages' => $rows->take($limit)->reverse()->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function store(Request $request, Chat $chat, UrlFetcher $fetcher): JsonResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192'],
            'url' => ['nullable', 'url', 'max:2048'],
            // ISO 639-1 from the UI's language toggle. Optional — if the
            // client doesn't send it (e.g. older API caller), the chat's
            // existing language is preserved.
            'language' => ['nullable', 'string', 'in:'.implode(',', LanguageDetector::supportedIsoCodes())],
        ]);

        // Re-align the chat's output language with whatever the UI is set to
        // right now. Personas / scribe / chair read $chat->language at
        // generation time so the next turn is in the new language. We leave
        // the already-rendered transcript untouched.
        if (! empty($validated['language'])) {
            $resolved = LanguageDetector::fromIso($validated['language']);
            if ($resolved && $resolved !== $chat->language) {
                $chat->update(['language' => $resolved]);
            }
        }

        $attachments = [];

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $attachments[] = [
                'type' => ChatAttachment::TYPE_IMAGE,
                'file_path' => $file->store('chat-attachments/'.$chat->id, config('filesystems.default')),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ];
        }

        if (! empty($validated['url'])) {
            try {
                $fetched = $fetcher->fetch($validated['url']);
                $attachments[] = [
                    'type' => ChatAttachment::TYPE_URL,
                    'url' => $fetched['url'],
                    'extracted_content' => $fetched['content'],
                ];
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'URL nije dohvaćen: '.$e->getMessage()], 422);
            }
        }

        // Pre-flight balance gate: refuse the send if the user has no funds
        // at all. We don't try to estimate the whole turn (too many unknowns)
        // — a tiny floor of €0.05 catches the obvious "wallet is empty" case
        // and returns a structured 402 so the frontend can show a top-up CTA
        // instead of letting the orchestrator chain fail mid-persona.
        $wallet = $this->wallets->forUser($request->user());
        $minFloor = (float) config('cortex.billing.min_send_balance', 0.05);
        if ($wallet->availableBalance() < $minFloor) {
            return response()->json([
                'ok' => false,
                'error' => 'insufficient_funds',
                'message' => 'Your wallet is empty. Top up to send messages to the boardroom.',
                'available' => round($wallet->availableBalance(), 6),
                'topup_url' => '/user/redeem-code',
            ], 402);
        }

        // The user is on the page — keep the continuous loop alive.
        Cache::put('cortex:heartbeat:'.$chat->id, true, ChatActionController::HEARTBEAT_TTL);

        try {
            $message = $this->orchestrator->sendUserMessage(
                $chat,
                $request->user(),
                $validated['content'],
                $attachments,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 429);
        }

        return response()->json(['ok' => true, 'message' => $message->load('attachments')]);
    }
}
