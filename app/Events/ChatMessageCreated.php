<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->message->chat_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['persona', 'attachments']);

        return [
            'message' => [
                'id' => $message->id,
                'chat_id' => $message->chat_id,
                'role' => $message->role,
                'content' => $message->content,
                'round_number' => $message->round_number,
                'turn_number' => $message->turn_number,
                'input_tokens' => $message->input_tokens,
                'output_tokens' => $message->output_tokens,
                'cost' => $message->cost,
                'model_used' => $message->model_used,
                'provider_used' => $message->provider_used,
                'response_time_ms' => $message->response_time_ms,
                'created_at' => $message->created_at?->toIso8601String(),
                'persona' => $message->persona ? [
                    'id' => $message->persona->id,
                    'name' => $message->persona->name,
                    'title' => $message->persona->title,
                    'avatar_emoji' => $message->persona->avatar_emoji,
                    'avatar_color' => $message->persona->avatar_color,
                    'is_scribe' => $message->persona->is_scribe,
                ] : null,
                'attachments' => $message->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'url' => $a->url,
                    'file_path' => $a->file_path,
                    'mime_type' => $a->mime_type,
                ])->all(),
            ],
        ];
    }
}
