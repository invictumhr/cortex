<?php

namespace App\Console\Commands;

use App\Models\Persona;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove ephemeral architect-generated personas left fully orphaned — no
 * messages AND attached to no chat (leftovers from failed runs). Personas
 * that belong to a chat (even a pending one) or produced messages are kept.
 */
class CortexPrune extends Command
{
    protected $signature = 'cortex:prune {--json : Strojno-čitljiv JSON izlaz}';

    protected $description = 'Počisti ephemeral persone bez poruka (smeće iz neuspjelih rasprava)';

    public function handle(): int
    {
        $orphans = Persona::query()
            ->where('is_ephemeral', true)
            ->whereDoesntHave('messages')
            ->whereDoesntHave('chats')
            ->get();

        foreach ($orphans as $persona) {
            $persona->chats()->detach();
            $persona->delete();
        }

        $count = $orphans->count();

        if ($this->option('json')) {
            $this->output->writeln(
                json_encode(['ok' => true, 'pruned' => $count], JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->info("Počišćeno {$count} ephemeral persona bez poruka.");
        }

        return self::SUCCESS;
    }
}
