<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageCreated;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\PowerShell\PowerShellClient;
use App\Services\PowerShell\PowerShellGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PowerShellController extends Controller
{
    public function __construct(
        private PowerShellGuard $guard,
        private PowerShellClient $client,
    ) {}

    public function execute(Request $request, Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
            'persona_id' => ['nullable', 'integer', 'exists:personas,id'],
            'approved' => ['boolean'],
        ]);

        $precheck = $this->guard->check($validated['command']);

        if (! $precheck['allowed']) {
            return response()->json([
                'ok' => false,
                'blocked' => true,
                'reason' => $precheck['reason'],
            ]);
        }

        try {
            $result = $this->client->execute(
                $validated['command'],
                $chat->id,
                $validated['persona_id'] ?? null,
                (bool) ($validated['approved'] ?? false),
            );
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        // Surface the output in the transcript so personas can use it.
        if (empty($result['was_blocked'])) {
            $turn = (int) ($chat->messages()->max('turn_number') ?? 1);

            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'persona_id' => $validated['persona_id'] ?? null,
                'role' => ChatMessage::ROLE_SYSTEM,
                'content' => 'PowerShell: '.$validated['command'].' (exit '.($result['exit_code'] ?? '?').')',
                'round_number' => (int) $chat->current_round,
                'turn_number' => $turn,
                'has_attachments' => true,
            ]);

            $message->attachments()->create([
                'type' => ChatAttachment::TYPE_POWERSHELL_OUTPUT,
                'extracted_content' => (string) ($result['output'] ?? ''),
            ]);

            broadcast(new ChatMessageCreated($message->load('attachments')));
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
