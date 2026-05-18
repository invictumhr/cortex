<?php

namespace App\Http\Controllers;

use App\Models\ChatAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    /**
     * Stream a (privately stored) attachment file to its owning user.
     */
    public function show(Request $request, ChatAttachment $attachment): StreamedResponse
    {
        $chat = $attachment->message?->chat;

        abort_unless($chat !== null && $chat->user_id === $request->user()->id, 403);

        $disk = Storage::disk(config('filesystems.default', 'local'));

        abort_unless(filled($attachment->file_path) && $disk->exists($attachment->file_path), 404);

        return $disk->response($attachment->file_path);
    }
}
