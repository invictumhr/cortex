<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\ChatFeedback;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Record how useful a Cortex discussion turned out to be — the feedback loop.
 * Exposed to PowerShell as `cortex-feedback`.
 */
class CortexFeedback extends Command
{
    protected $signature = 'cortex:feedback
        {chat : ID Cortex chata}
        {rating : Ocjena korisnosti 1-5}
        {--note= : Kratak komentar}
        {--ideas= : Korisne ideje, odvojene zarezom}
        {--used= : Ideje koje su STVARNO implementirane, odvojene zarezom}
        {--source=cli : Izvor povratne informacije (cli|agent|ui)}
        {--json : Strojno-čitljiv JSON izlaz}';

    protected $description = 'Zabilježi povratnu informaciju o korisnosti Cortex rasprave';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $chat = Chat::find((int) $this->argument('chat'));
        if (! $chat) {
            return $this->bail('Chat nije pronađen.', $json);
        }

        $rating = (int) $this->argument('rating');
        if ($rating < 1 || $rating > 5) {
            return $this->bail('Ocjena mora biti cijeli broj od 1 do 5.', $json);
        }

        $ideas = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ideas')))));
        $used = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('used')))));

        $feedback = ChatFeedback::create([
            'chat_id' => $chat->id,
            'rating' => $rating,
            'comment' => $this->option('note'),
            'helpful_ideas' => $ideas,
            'used_ideas' => $used,
            'source' => $this->option('source') ?: 'cli',
        ]);

        $average = round((float) ChatFeedback::where('chat_id', $chat->id)->avg('rating'), 2);

        if ($json) {
            $this->output->writeln(json_encode([
                'ok' => true,
                'feedback_id' => $feedback->id,
                'chat_id' => $chat->id,
                'rating' => $rating,
                'used_ideas' => $used,
                'chat_average_rating' => $average,
            ], JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            $this->info("Zabilježeno — chat #{$chat->id}, ocjena {$rating}/5. Prosjek tog chata: {$average}/5.");
        }

        return self::SUCCESS;
    }

    private function bail(string $message, bool $json): int
    {
        if ($json) {
            $this->output->writeln(
                json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
