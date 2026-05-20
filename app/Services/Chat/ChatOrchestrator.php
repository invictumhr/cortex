<?php

namespace App\Services\Chat;

use App\Events\ChatMessageCreated;
use App\Events\RoundCompleted;
use App\Events\TurnCompleted;
use App\Jobs\ConcludeDiscussion;
use App\Jobs\GeneratePersonaResponse;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;

class ChatOrchestrator
{
    public function __construct(private UsageGuard $guard) {}

    /**
     * Persist the user's message and kick off the first round of a new turn.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function sendUserMessage(Chat $chat, User $user, string $content, array $attachments = []): ChatMessage
    {
        $this->guard->check();

        $turn = (int) ($chat->messages()->max('turn_number') ?? 0) + 1;

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => $content,
            'round_number' => 0,
            'turn_number' => $turn,
            'has_attachments' => $attachments !== [],
        ]);

        foreach ($attachments as $attachment) {
            $message->attachments()->create($attachment);
        }

        $chat->increment('total_messages');
        broadcast(new ChatMessageCreated($message->load('attachments')));

        // A continuous discussion that is already running just absorbs the
        // message — the loop surfaces it to the personas in the next round.
        if ($chat->continuous && $chat->status === Chat::STATUS_ACTIVE) {
            return $message;
        }

        // Otherwise (re)start the loop: a paused continuous discussion picks
        // up from its current round; a bounded turn opens fresh at round 1.
        $nextRound = $chat->continuous ? ((int) $chat->current_round + 1) : 1;

        $chat->update([
            'status' => Chat::STATUS_ACTIVE,
            'current_round' => $chat->continuous ? (int) $chat->current_round : 0,
        ]);

        $this->startRound($chat->refresh(), $turn, $nextRound);

        return $message;
    }

    /**
     * Snapshot the speaker order for a round and dispatch the first persona job.
     */
    public function startRound(Chat $chat, int $turn, int $round): void
    {
        $chat->update(['current_round' => $round]);

        $speakerIds = $chat->activePersonas()
            ->where('is_scribe', false)
            ->orderBy('sort_order')
            ->pluck('personas.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($speakerIds === []) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
            broadcast(new RoundCompleted($chat, $round));
            broadcast(new TurnCompleted($chat->refresh(), 'no_personas'));

            return;
        }

        GeneratePersonaResponse::dispatch($chat->id, $turn, $round, $speakerIds, 0);
    }

    /**
     * Stop the discussion. An explicit user pause ($conclude = true) also
     * triggers a final synthesis + Chair verdict for a continuous chat;
     * a silent stop (leaving the page) just halts the loop.
     */
    public function pause(Chat $chat, bool $conclude = false): void
    {
        $chat->update(['status' => Chat::STATUS_PAUSED]);

        if ($conclude && $chat->continuous) {
            ConcludeDiscussion::dispatch($chat->id);
        }
    }

    public function resume(Chat $chat): void
    {
        $chat->update(['status' => Chat::STATUS_ACTIVE]);

        $turn = (int) ($chat->messages()->max('turn_number') ?? 1);
        $nextRound = (int) $chat->current_round + 1;

        if ($chat->continuous || $nextRound <= (int) $chat->rounds_per_turn) {
            $this->startRound($chat, $turn, $nextRound);
        } else {
            broadcast(new TurnCompleted($chat, 'completed'));
        }
    }

    /**
     * Increase the round count. An in-flight turn picks this up automatically;
     * a finished turn is resumed so the extra rounds run immediately.
     */
    public function addRounds(Chat $chat, int $extra): void
    {
        $max = (int) config('cortex.max_rounds', 200);
        $turnWasComplete = (int) $chat->current_round >= (int) $chat->rounds_per_turn;

        $chat->update([
            'rounds_per_turn' => min($max, (int) $chat->rounds_per_turn + max(1, $extra)),
        ]);

        if ($turnWasComplete
            && $chat->status === Chat::STATUS_ACTIVE
            && (int) $chat->current_round >= 1
            && (int) $chat->current_round < (int) $chat->rounds_per_turn
        ) {
            $turn = (int) ($chat->messages()->max('turn_number') ?? 1);
            $this->startRound($chat, $turn, (int) $chat->current_round + 1);
        }
    }
}
