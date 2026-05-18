<?php

namespace App\Events;

use App\Models\ScribeSummary;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScribeSummarized implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ScribeSummary $summary) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->summary->chat_id)];
    }

    public function broadcastAs(): string
    {
        return 'scribe.summarized';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'summary' => [
                'id' => $this->summary->id,
                'chat_id' => $this->summary->chat_id,
                'summary' => $this->summary->summary,
                'key_decisions' => $this->summary->key_decisions,
                'key_ideas' => $this->summary->key_ideas,
                'open_questions' => $this->summary->open_questions,
                'action_items' => $this->summary->action_items,
                'assumptions_to_validate' => $this->summary->assumptions_to_validate,
                'created_at' => $this->summary->created_at?->toIso8601String(),
            ],
        ];
    }
}
