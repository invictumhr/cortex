<?php

namespace App\Jobs;

use App\Events\ChatMessageCreated;
use App\Events\PersonaIsTyping;
use App\Events\RoundCompleted;
use App\Events\TurnCompleted;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Services\Chat\ChairService;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\PersonaResponder;
use App\Services\Chat\ScribeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GeneratePersonaResponse implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $personaIds  Ordered speaker snapshot for this round.
     */
    public function __construct(
        public int $chatId,
        public int $turn,
        public int $round,
        public array $personaIds,
        public int $position,
    ) {}

    public function handle(ChatOrchestrator $orchestrator, PersonaResponder $responder, ScribeService $scribe, ChairService $chair): void
    {
        $chat = Chat::find($this->chatId);

        if (! $chat) {
            return;
        }

        if (Cache::get((string) config('cortex.kill_switch_key'))) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
            broadcast(new TurnCompleted($chat->refresh(), 'kill_switch'));

            return;
        }

        if ($chat->status !== Chat::STATUS_ACTIVE) {
            return;
        }

        // A continuous discussion runs only while the user's chat page is
        // open — once the heartbeat lapses, the loop stops itself.
        if ($chat->continuous && ! Cache::has('cortex:heartbeat:'.$chat->id)) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
            broadcast(new TurnCompleted($chat->refresh(), 'left'));

            return;
        }

        $personaId = $this->personaIds[$this->position] ?? null;

        if ($personaId !== null) {
            $persona = Persona::with('aiModel.provider')->find($personaId);
            $stillActive = $chat->activePersonas()->where('personas.id', $personaId)->exists();

            if ($persona && $stillActive) {
                broadcast(new PersonaIsTyping($chat->id, $persona->id, $persona->name, $this->round));

                try {
                    $responder->respond($chat, $persona, $this->round, $this->turn);
                } catch (Throwable $e) {
                    report($e);
                    $errorMessage = ChatMessage::create([
                        'chat_id' => $chat->id,
                        'role' => ChatMessage::ROLE_SYSTEM,
                        'content' => "⚠️ {$persona->name} nije uspio odgovoriti: ".$e->getMessage(),
                        'round_number' => $this->round,
                        'turn_number' => $this->turn,
                    ]);
                    broadcast(new ChatMessageCreated($errorMessage));
                }
            }
        }

        $chat->refresh();

        // Budget guard — pause the chat if the cost ceiling is reached.
        if ((float) $chat->total_cost >= (float) config('cortex.budget_limit')) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
            broadcast(new TurnCompleted($chat->refresh(), 'budget_exceeded'));

            return;
        }

        // A pause requested mid-round is honoured before the next persona.
        if ($chat->status !== Chat::STATUS_ACTIVE) {
            return;
        }

        $nextPosition = $this->position + 1;

        if ($nextPosition < count($this->personaIds)) {
            self::dispatch($this->chatId, $this->turn, $this->round, $this->personaIds, $nextPosition);

            return;
        }

        // Round finished.
        broadcast(new RoundCompleted($chat, $this->round));

        // A continuous chat has no final round — it loops until paused.
        $isFinalRound = ! $chat->continuous && $this->round >= (int) $chat->rounds_per_turn;

        // `--fast` disables the scribe by setting an astronomically high interval.
        if ((int) $chat->scribe_interval < 1000000) {
            if ($isFinalRound) {
                $scribe->summarize($chat, true);
                $chair->decide($chat);
            } else {
                $scribe->maybeSummarize($chat, $this->round);
            }
        }

        $chat->refresh();

        if (! $isFinalRound && $chat->status === Chat::STATUS_ACTIVE) {
            $orchestrator->startRound($chat, $this->turn, $this->round + 1);
        } else {
            broadcast(new TurnCompleted($chat, $isFinalRound ? 'completed' : 'paused'));
        }
    }

    public function failed(?Throwable $e): void
    {
        $chat = Chat::find($this->chatId);

        if ($chat) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
            broadcast(new TurnCompleted($chat->refresh(), 'error'));
        }
    }
}
