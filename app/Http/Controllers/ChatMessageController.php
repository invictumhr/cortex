<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Services\Chat\ChatOrchestrator;
use App\Services\UrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChatMessageController extends Controller
{
    public function __construct(private ChatOrchestrator $orchestrator) {}

    public function index(Request $request, Chat $chat): JsonResponse
    {
        Gate::authorize('view', $chat);

        return response()->json([
            'messages' => $chat->messages()
                ->with(['persona:id,name,title,avatar_emoji,avatar_color,is_scribe', 'attachments'])
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request, Chat $chat, UrlFetcher $fetcher): JsonResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:20000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192'],
            'url' => ['nullable', 'url', 'max:2048'],
        ]);

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
