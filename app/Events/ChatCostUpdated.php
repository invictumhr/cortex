<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatCostUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Chat $chat) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->chat->id)];
    }

    public function broadcastAs(): string
    {
        return 'cost.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'total_cost' => $this->chat->total_cost,
            'total_input_tokens' => $this->chat->total_input_tokens,
            'total_output_tokens' => $this->chat->total_output_tokens,
            'total_messages' => $this->chat->total_messages,
            'current_round' => $this->chat->current_round,
        ];
    }
}
