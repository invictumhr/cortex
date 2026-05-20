<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ChairService;
use App\Services\Chat\ScribeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wrap up a continuous discussion when the user pauses: a final cumulative
 * scribe synthesis plus a Chair verdict. Skipped if the user resumed before
 * this ran, or if nothing new was said since the last verdict.
 */
class ConcludeDiscussion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $chatId) {}

    public function handle(ScribeService $scribe, ChairService $chair): void
    {
        $chat = Chat::find($this->chatId);

        if (! $chat || $chat->status === Chat::STATUS_ACTIVE) {
            return; // chat gone, or the user resumed before this job ran
        }

        $lastVerdictId = (int) ($chat->messages()
            ->where('metadata->chair_decision', true)
            ->max('id') ?? 0);

        $hasNewContributions = $chat->messages()
            ->where('role', ChatMessage::ROLE_PERSONA)
            ->where('id', '>', $lastVerdictId)
            ->whereNull('metadata->chair_decision')
            ->exists();

        if (! $hasNewContributions) {
            return; // nothing new since the last verdict
        }

        $scribe->summarize($chat, true);
        $chair->decide($chat);
    }
}
