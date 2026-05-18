<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Persona;
use App\Models\User;
use App\Services\Chat\ChatOrchestrator;
use App\Services\PowerShell\PowerShellGuard;
use Illuminate\Console\Command;

/**
 * Real end-to-end orchestration test. Runs a multi-persona, multi-round turn
 * inline (sync queue) using whichever providers currently have an API key.
 * Consumes real AI tokens.
 */
class CortexSmokeTest extends Command
{
    protected $signature = 'cortex:smoke-test {--rounds=2} {--scribe=5} {--keep}';

    protected $description = 'Pokreni stvarni multi-persona orkestracijski test (troši AI tokene)';

    public function handle(ChatOrchestrator $orchestrator, PowerShellGuard $guard): int
    {
        // Run the entire turn inline and route broadcasts to the log.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $this->info('=== CORTEX SMOKE TEST ===');

        $user = User::first();
        if (! $user) {
            $this->error('Nema korisnika — pokreni: php artisan db:seed');

            return self::FAILURE;
        }

        $personas = Persona::query()
            ->where('is_active', true)
            ->where('is_scribe', false)
            ->whereHas('aiModel.provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('aiModel')
            ->get()
            ->sortBy(fn (Persona $p) => (float) $p->aiModel->input_cost_per_1m_tokens)
            ->take(3)
            ->values();

        if ($personas->count() < 1) {
            $this->error('Nijedna persona nema providera s API ključem. Dodaj ključ u .env i pokreni db:seed.');

            return self::FAILURE;
        }

        $this->line('Persone: '.$personas->map(fn ($p) => $p->name.' ('.$p->aiModel->model_string.')')->implode(', '));

        $rounds = (int) $this->option('rounds');

        $chat = $user->chats()->create([
            'title' => 'Smoke test '.now()->format('H:i:s'),
            'description' => 'Automatski integracijski test',
            'rounds_per_turn' => $rounds,
            'scribe_interval' => (int) $this->option('scribe'),
            'status' => Chat::STATUS_ACTIVE,
        ]);

        foreach ($personas as $persona) {
            $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);
        }

        $this->line("Chat #{$chat->id} kreiran — {$rounds} kruga/turn.");
        $this->newLine();
        $this->line('Šaljem poruku i pokrećem orkestraciju...');

        $startedAt = microtime(true);

        try {
            $orchestrator->sendUserMessage(
                $chat,
                $user,
                'Trebamo li lansirati mobilnu aplikaciju ovaj kvartal? Odgovori kratko, 2-3 rečenice.',
            );
        } catch (\Throwable $e) {
            $this->error('Orkestrator greška: '.$e->getMessage());
        }

        $elapsed = round(microtime(true) - $startedAt, 1);
        $chat->refresh();

        $this->newLine();
        $this->info("--- REZULTAT ({$elapsed}s) ---");
        $this->table(['Metrika', 'Vrijednost'], [
            ['Status', $chat->status],
            ['Krug', "{$chat->current_round} / {$chat->rounds_per_turn}"],
            ['Poruka', $chat->total_messages],
            ['Trošak (EUR)', number_format((float) $chat->total_cost, 6)],
            ['Tokeni ulaz/izlaz', "{$chat->total_input_tokens} / {$chat->total_output_tokens}"],
            ['Scribe sažetaka', $chat->scribeSummaries()->count()],
        ]);

        $this->newLine();
        $this->info('--- TRANSKRIPT ---');
        foreach ($chat->messages()->with('persona')->orderBy('id')->get() as $message) {
            $who = match ($message->role) {
                'user' => 'TI',
                'scribe' => 'SCRIBE',
                'system' => 'SUSTAV',
                default => $message->persona?->name ?? 'Persona',
            };
            $this->line("<fg=cyan>[krug {$message->round_number}] {$who}:</>");
            $this->line('  '.mb_substr(trim((string) $message->content), 0, 320));
        }

        $this->newLine();
        $this->info('--- POWERSHELL GUARD ---');
        $permission = \App\Models\PowerShellPermission::current();
        $permission->update(['is_enabled' => true]);

        foreach (['git status', 'Remove-Item C:\\temp', 'dir', 'Stop-Process -Name node'] as $command) {
            $result = $guard->check($command);
            $verdict = $result['allowed'] ? '<fg=green>DOZVOLJENO</>' : '<fg=red>BLOKIRANO</>';
            $this->line("  \"{$command}\" => {$verdict}".($result['reason'] ? " ({$result['reason']})" : ''));
        }

        $permission->update(['is_enabled' => false]); // restore the safe default

        $this->newLine();

        if (! $this->option('keep')) {
            $this->comment("Chat #{$chat->id} ostavljen u bazi — vidljiv u UI. (--keep je default)");
        }

        $this->info('=== SMOKE TEST GOTOV ===');

        return self::SUCCESS;
    }
}
