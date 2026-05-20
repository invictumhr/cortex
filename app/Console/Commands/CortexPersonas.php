<?php

namespace App\Console\Commands;

use App\Models\Persona;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * List every Cortex persona, its role, and the model that powers it.
 * Shared by the `cortex` help screen (bin/cortex.ps1) and `cortex:discuss` usage.
 */
class CortexPersonas extends Command
{
    protected $signature = 'cortex:personas {--json : Strojno-čitljiv JSON izlaz}';

    protected $description = 'Popis svih Cortex persona, njihovih uloga i modela koji ih pogone';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        try {
            $personas = Persona::query()
                ->where('is_ephemeral', false)
                ->with('aiModel')
                ->orderBy('sort_order')
                ->get();
        } catch (Throwable $e) {
            if ($json) {
                $this->output->writeln(
                    json_encode(['ok' => false, 'error' => 'Baza nedostupna: '.$e->getMessage()], JSON_UNESCAPED_UNICODE),
                    OutputInterface::OUTPUT_RAW,
                );
            } else {
                $this->warn('  Popis persona nedostupan — baza nije dostupna.');
            }

            return self::FAILURE;
        }

        if ($json) {
            $this->output->writeln(json_encode([
                'ok' => true,
                'version' => config('cortex.api_version'),
                'count' => $personas->reject(fn (Persona $p) => $p->is_scribe || $p->is_chair)->count(),
                'personas' => $personas->map(fn (Persona $p) => [
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'title' => $p->title,
                    'model' => $p->aiModel?->name,
                    'model_string' => $p->aiModel?->model_string,
                    'is_scribe' => (bool) $p->is_scribe,
                    'is_chair' => (bool) $p->is_chair,
                ])->values(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->renderTable($personas);

        return self::SUCCESS;
    }

    private function renderTable(Collection $personas): void
    {
        $pad = static fn (string $s, int $w): string => $s.str_repeat(' ', max(0, $w - mb_strlen($s)));
        $modelName = static fn (Persona $p): string => $p->aiModel?->name ?? 'bez modela';

        $slugW = (int) $personas->map(fn (Persona $p) => mb_strlen($p->slug))->max();
        $titleW = (int) $personas->map(fn (Persona $p) => mb_strlen((string) $p->title))->max();
        $modelW = (int) $personas->map(fn (Persona $p) => mb_strlen($modelName($p)))->max();

        [$special, $speakers] = $personas->partition(fn (Persona $p) => $p->is_scribe || $p->is_chair);

        $this->newLine();
        $this->line('  <options=bold>CORTEX PERSONE</> — '.$speakers->count().' stručnjaka u panelu (+ scribe i chair)');
        $this->line('  Personu biraš slugom, npr.  cortex "<tema>" -Personas marco,helena,zara');
        $this->newLine();
        $this->line('  '.$pad('slug', $slugW).'  '.$pad('uloga', $titleW).'  model');
        $this->line('  '.str_repeat('-', $slugW).'  '.str_repeat('-', $titleW).'  '.str_repeat('-', $modelW));

        foreach ($speakers as $p) {
            $this->line(
                '  <fg=cyan>'.$pad($p->slug, $slugW).'</>  '
                .$pad((string) $p->title, $titleW).'  '
                .'<fg=yellow>'.$modelName($p).'</>'
            );
        }

        foreach ($special as $p) {
            $this->newLine();
            $note = $p->is_chair ? '(donosi završnu odluku)' : '(sažima raspravu, ne glasa)';
            $this->line(
                '  <fg=magenta>'.$pad($p->slug, $slugW).'</>  '
                .$pad((string) $p->title, $titleW).'  '
                .'<fg=yellow>'.$pad($modelName($p), $modelW).'</>  <fg=default>'.$note.'</>'
            );
        }

        $this->newLine();
    }
}
