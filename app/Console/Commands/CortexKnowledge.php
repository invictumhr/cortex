<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeNote;
use App\Services\Chat\KnowledgeService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show or rebuild the global Cortex knowledge memory.
 * Exposed to PowerShell as `cortex-knowledge`.
 */
class CortexKnowledge extends Command
{
    protected $signature = 'cortex:knowledge
        {--rebuild : Regeneriraj konsolidirani digest iz svih zabilješki}
        {--limit=15 : Broj zadnjih zabilješki za prikaz}
        {--json : Strojno-čitljiv JSON izlaz}';

    protected $description = 'Prikaži ili obnovi Cortex globalnu memoriju (akumulirano znanje)';

    public function handle(KnowledgeService $knowledge): int
    {
        $json = (bool) $this->option('json');

        if ($this->option('rebuild')) {
            if (! $json) {
                $this->comment('Konsolidiram zabilješke u digest...');
            }
            $knowledge->rebuildDigest();
        }

        $base = KnowledgeBase::current();
        $notes = KnowledgeNote::query()
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'chat_id', 'content']);

        if ($json) {
            $this->output->writeln(json_encode([
                'ok' => true,
                'notes_count' => (int) $base->notes_count,
                'digest' => $base->digest,
                'digested_at' => $base->digested_at?->toIso8601String(),
                'recent_notes' => $notes->map(fn ($n) => [
                    'id' => $n->id,
                    'chat_id' => $n->chat_id,
                    'content' => $n->content,
                ])->values(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->info('=== CORTEX GLOBALNA MEMORIJA ===');
        $this->line("Ukupno zabilješki: {$base->notes_count}");
        $this->newLine();

        if (filled($base->digest)) {
            $this->line('<options=bold>DIGEST:</>');
            $this->line(trim((string) $base->digest));
        } else {
            $this->comment('Digest još nije generiran — pokreni: cortex-knowledge --rebuild');
        }

        $this->newLine();
        $this->line('<options=bold>ZADNJE ZABILJEŠKE:</>');
        if ($notes->isEmpty()) {
            $this->line('  (još nema zabilješki — nastaju iz scribe sažetaka)');
        }
        foreach ($notes as $n) {
            $this->line("  - {$n->content}".($n->chat_id ? " (chat #{$n->chat_id})" : ''));
        }

        return self::SUCCESS;
    }
}
