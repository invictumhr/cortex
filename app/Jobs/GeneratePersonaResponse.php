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
use Illuminate\Support\Facades\Log;
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
            // Job was queued while chat was active, but something paused the chat
            // before it ran. For continuous chats this is the normal "user left" path
            // (handled below) — but for bounded CLI runs it's a bug that would otherwise
            // strand the discussion silently. Surface it.
            $this->reportUnexpectedPause($chat, 'pre_respond');

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
        // For continuous chats this is normal (user clicked pause). For bounded CLI
        // runs this should never happen — if it does, it's a bug we want visible.
        if ($chat->status !== Chat::STATUS_ACTIVE) {
            $this->reportUnexpectedPause($chat, 'post_respond');

            return;
        }

        $nextPosition = $this->position + 1;

        if ($nextPosition < count($this->personaIds)) {
            self::dispatch($this->chatId, $this->turn, $this->round, $this->personaIds, $nextPosition);

            return;
        }

        // Round finished.
        broadcast(new RoundCompleted($chat, $this->round));

        // A continuous chat has no final round in the bounded-CLI sense — it
        // loops until either the user pauses, the heartbeat lapses, or we hit
        // an auto-checkpoint (continuous chats only).
        $isFinalRound = ! $chat->continuous && $this->round >= (int) $chat->rounds_per_turn;

        // Checkpoint: in continuous mode, every N rounds (config-controlled)
        // we PAUSE the loop and force a full scribe synthesis + Chair verdict.
        // User clicks Resume to keep going. Treated as a "soft end" — same
        // scribe/chair pair as a real final round, but the chat reopens.
        $checkpointEvery = (int) config('cortex.continuous_checkpoint_round_interval', 2);
        $isCheckpoint = $chat->continuous
            && $checkpointEvery > 0
            && $this->round > 0
            && $this->round % $checkpointEvery === 0;

        // `--fast` disables the scribe by setting an astronomically high interval.
        if ((int) $chat->scribe_interval < 1000000) {
            if ($isFinalRound || $isCheckpoint) {
                $scribe->summarize($chat, true);
                $chair->decide($chat);
            } else {
                $scribe->maybeSummarize($chat, $this->round);
            }
        }

        // A checkpoint pauses the chat so the user can read the synthesis
        // before deciding to continue. Web heartbeat keeps the page open;
        // resume() restarts the loop from the next round.
        if ($isCheckpoint && ! $isFinalRound) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);
        }

        $chat->refresh();

        $stopping = $isFinalRound || $isCheckpoint || $chat->status !== Chat::STATUS_ACTIVE;

        if (! $stopping) {
            $orchestrator->startRound($chat, $this->turn, $this->round + 1);
        } else {
            $reason = match (true) {
                $isFinalRound => 'completed',
                $isCheckpoint => 'checkpoint',
                default       => 'paused',
            };
            broadcast(new TurnCompleted($chat, $reason));
        }
    }

    public function failed(?Throwable $e): void
    {
        $chat = Chat::find($this->chatId);

        if ($chat) {
            $chat->update(['status' => Chat::STATUS_PAUSED]);

            // Leave a breadcrumb in the chat itself so the failure is visible in the
            // UI / CLI output — without this, a sync-mode failure that bypassed the
            // try/catch in handle() would silently strand the discussion.
            $systemMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => ChatMessage::ROLE_SYSTEM,
                'content' => '⚠️ Job failed — discussion paused. '.($e ? $e->getMessage() : 'no exception details'),
                'round_number' => $this->round,
                'turn_number' => $this->turn,
            ]);
            broadcast(new ChatMessageCreated($systemMessage));
            broadcast(new TurnCompleted($chat->refresh(), 'error'));
        }
    }

    /**
     * The chat is paused mid-turn when it shouldn't be. For continuous web chats this
     * is the normal "user clicked pause" path (silent return is correct). For bounded
     * CLI runs, this means *something* set the status to paused that we can't trace —
     * surface it loudly so the CLI shows the problem and we have a breadcrumb to debug.
     */
    private function reportUnexpectedPause(Chat $chat, string $where): void
    {
        $tag = "chat={$chat->id} round={$this->round} pos={$this->position}/".count($this->personaIds)." where={$where}";

        // Continuous chats use status=paused as the normal "user closed the tab" exit
        // path — silent return is correct, no need to alarm.
        if ($chat->continuous) {
            Log::info("[gpr] continuous chat paused mid-turn (expected): $tag");

            return;
        }

        Log::warning("[gpr] UNEXPECTED PAUSE mid-turn: $tag");

        $systemMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => ChatMessage::ROLE_SYSTEM,
            'content' => "⚠️ Rasprava je neočekivano pauzirana usred kruga {$this->round} (na poziciji ".($this->position + 1).' od '.count($this->personaIds).'). Pokreni ponovno s --chat='.$chat->id.' ili nastavi pauziranu raspravu.',
            'round_number' => $this->round,
            'turn_number' => $this->turn,
        ]);
        broadcast(new ChatMessageCreated($systemMessage));
        broadcast(new TurnCompleted($chat, 'paused_unexpectedly'));
    }
}
