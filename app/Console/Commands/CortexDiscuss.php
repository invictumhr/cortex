<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Persona;
use App\Models\User;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\KnowledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Drive a Cortex boardroom discussion from the CLI.
 * Exposed to PowerShell as the `cortex` command via bin/cortex.ps1.
 */
class CortexDiscuss extends Command
{
    protected $signature = 'cortex:discuss
        {message? : Tema ili poruka boardroomu (izostavi za pomoć)}
        {--chat= : Nastavi postojeći chat po ID-u}
        {--personas= : Slugovi persona odvojeni zarezom (za novi chat)}
        {--rounds=2 : Krugova po unosu}
        {--title= : Naslov chata (za novi chat)}
        {--scribe=50 : Interval scribe sažetka}
        {--fast : Brzi način — 1 krug, bez scribe sažetka}
        {--memory : Ubaci akumulirano znanje (knowledge digest) u kontekst}
        {--json : Strojno-čitljiv JSON izlaz za druge agente}';

    protected $description = 'Pokreni ili nastavi Cortex boardroom raspravu iz CLI-ja';

    public function handle(ChatOrchestrator $orchestrator, KnowledgeService $knowledge): int
    {
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $json = (bool) $this->option('json');
        $topic = trim((string) $this->argument('message'));

        if ($topic === '' || in_array(mb_strtolower($topic), ['help', '?'], true)) {
            $this->showUsage($json);

            return self::SUCCESS;
        }

        $message = $topic;

        if ($this->option('memory')) {
            $digest = $knowledge->digest();
            if ($digest !== '') {
                $message = "[AKUMULIRANO ZNANJE IZ PROŠLIH CORTEX RASPRAVA]\n".$digest
                    ."\n\n[TRENUTNA TEMA]\n".$topic;
            }
        }

        $user = User::first();
        if (! $user) {
            return $this->bail('Nema korisnika. Pokreni: php artisan db:seed', $json);
        }

        $chat = $this->option('chat')
            ? Chat::find((int) $this->option('chat'))
            : $this->createChat($user, $json);

        if (! $chat) {
            return $this->bail('Chat nije pronađen.', $json);
        }

        $speakers = $chat->activePersonas()->where('is_scribe', false)->orderBy('sort_order')->get();
        if ($speakers->isEmpty()) {
            return $this->bail('Chat nema aktivnih persona — provjeri API ključeve.', $json);
        }

        $beforeId = (int) ($chat->messages()->max('id') ?? 0);

        if (! $json) {
            $this->info("=== Chat #{$chat->id}: {$chat->title} ===");
            $this->line('Persone: '.$speakers->pluck('name')->implode(', ')." | Krugova: {$chat->rounds_per_turn}");
            $this->newLine();
            $this->line('<options=bold;fg=blue>TI:</> '.$topic);
            $this->newLine();
            $this->comment('Generiram raspravu...');
        }

        $startedAt = microtime(true);

        try {
            $orchestrator->sendUserMessage($chat, $user, $message);
        } catch (Throwable $e) {
            return $this->bail('Orkestrator greška: '.$e->getMessage(), $json);
        }

        $elapsed = round(microtime(true) - $startedAt, 1);
        $chat->refresh();

        $new = $chat->messages()
            ->with('persona')
            ->where('id', '>', $beforeId)
            ->where('role', '!=', 'user')
            ->orderBy('id')
            ->get();

        if ($json) {
            $scribeSummary = $chat->latestScribeSummary();

            $this->output->writeln(json_encode([
                'ok' => true,
                'version' => config('cortex.api_version'),
                'chat_id' => $chat->id,
                'title' => $chat->title,
                'status' => $chat->status,
                'rounds_per_turn' => (int) $chat->rounds_per_turn,
                'elapsed_seconds' => $elapsed,
                'cost_eur' => round((float) $chat->total_cost, 6),
                'input_tokens' => (int) $chat->total_input_tokens,
                'output_tokens' => (int) $chat->total_output_tokens,
                'speakers' => $speakers->pluck('name')->values(),
                'turn_messages' => $new->map(fn ($m) => [
                    'round' => (int) $m->round_number,
                    'role' => $m->role,
                    'persona' => $m->persona?->name,
                    'model' => $m->model_used,
                    'content' => trim((string) $m->content),
                ])->values(),
                'scribe_summary' => $scribeSummary?->summary,
                'scribe' => $scribeSummary ? [
                    'key_ideas' => $scribeSummary->key_ideas ?? [],
                    'key_decisions' => $scribeSummary->key_decisions ?? [],
                    'open_questions' => $scribeSummary->open_questions ?? [],
                    'action_items' => $scribeSummary->action_items ?? [],
                    'assumptions_to_validate' => $scribeSummary->assumptions_to_validate ?? [],
                ] : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($new as $m) {
            $who = match ($m->role) {
                'scribe' => '📝 SCRIBE',
                'system' => '⚙ SUSTAV',
                default => trim(($m->persona?->avatar_emoji ?? '').' '.($m->persona?->name ?? 'Persona')),
            };
            $this->line("<options=bold;fg=cyan>[krug {$m->round_number}] {$who}</>");
            $this->line(trim((string) $m->content));
            $this->newLine();
        }

        $this->info(sprintf(
            'Gotovo za %ss — ukupno %d poruka, trošak €%s, tokeni %d/%d. Otvori u UI: /chats/%d',
            $elapsed,
            $chat->total_messages,
            number_format((float) $chat->total_cost, 6),
            $chat->total_input_tokens,
            $chat->total_output_tokens,
            $chat->id,
        ));

        return self::SUCCESS;
    }

    private function showUsage(bool $json): void
    {
        if ($json) {
            $this->output->writeln(json_encode([
                'tool' => 'cortex',
                'version' => config('cortex.api_version'),
                'description' => 'Vijeće AI stručnjaka — pokreće raspravu više AI persona (svaka na svom modelu) o zadanoj temi i vraća strukturiranu sintezu.',
                'usage' => 'cortex "<tema>" [--personas=slug,slug] [--rounds=N] [--scribe=N] [--fast] [--memory] [--chat=ID] [--json]',
                'parameters' => [
                    'message' => 'Tema/pitanje boardroomu. Obavezno za pokretanje rasprave.',
                    '--personas' => 'Slugovi persona, zarezom odvojeni. Izostavljeno => bira 5 automatski.',
                    '--rounds' => 'Broj krugova rasprave 1-200 (default 2). Krug 1 je neovisan, krug 2+ je rasprava.',
                    '--scribe' => 'Prag poruka za scribe sažetak (default 50).',
                    '--fast' => 'Brzi način: 1 krug, bez scribe sažetka.',
                    '--memory' => 'Ubaci akumulirano globalno znanje (knowledge digest) u kontekst rasprave.',
                    '--title' => 'Naslov rasprave kod novog chata.',
                    '--chat' => 'ID postojeće rasprave — nastavlja je uz sačuvani kontekst.',
                    '--json' => 'Strojno-čitljiv JSON izlaz.',
                ],
                'persona_slugs' => Persona::query()->where('is_scribe', false)->orderBy('sort_order')->pluck('slug')->all(),
                'json_output_fields' => ['ok', 'version', 'chat_id', 'cost_eur', 'input_tokens', 'output_tokens', 'speakers', 'turn_messages', 'scribe_summary', 'scribe'],
                'related_commands' => ['cortex:personas — popis persona, uloga i modela', 'cortex:feedback — ocjena korisnosti rasprave', 'cortex:knowledge — globalna memorija'],
                'example' => 'cortex "Kako optimizirati checkout na webshopu?" --personas=marco,helena,kira --rounds=2 --json',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return;
        }

        foreach ([
            '',
            '  CORTEX — vijeće AI stručnjaka',
            '  Pokreće raspravu više AI persona o zadanoj temi i vraća strukturiranu sintezu.',
            '',
            '  KORIŠTENJE',
            '    cortex "<tema>" [opcije]',
            '',
            '  OPCIJE',
            '    --personas=slug,slug   Stručnjaci u panelu (izostavljeno => bira 5 automatski)',
            '    --rounds=N             Krugova rasprave: 1 brzo, 2-3 temeljito (default 2)',
            '    --scribe=N             Prag za scribe sažetak (default 50; stavi 8 za kratke)',
            '    --fast                 Brzi način: 1 krug, bez scribe sažetka',
            '    --memory               Ubaci akumulirano znanje u kontekst rasprave',
            '    --title="..."          Naslov rasprave',
            '    --chat=ID              Nastavi postojeću raspravu (produbljivanje)',
            '    --json                 Strojno-čitljiv JSON izlaz (za druge agente)',
            '',
            '  PRIMJERI',
            '    cortex "Kako optimizirati checkout na webshopu?"',
            '    cortex "..." --personas=marco,helena,chen,kira,petra --rounds=2 --json',
            '    cortex "Brzo mišljenje?" --personas=marco --fast',
            '',
            '  SRODNE NAREDBE',
            '    cortex-feedback <chat> <1-5>   Ocijeni korisnost rasprave',
            '    cortex-knowledge [--rebuild]   Globalna memorija (akumulirano znanje)',
        ] as $line) {
            $this->line($line);
        }

        $this->call('cortex:personas');

        foreach ([
            '  Strojni opis parametara (za agente):  cortex --json',
            '',
        ] as $line) {
            $this->line($line);
        }
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

    private function createChat(User $user, bool $json): Chat
    {
        $slugs = array_filter(array_map('trim', explode(',', (string) $this->option('personas'))));

        $personas = $slugs !== []
            ? Persona::whereIn('slug', $slugs)->where('is_scribe', false)->with('aiModel.provider')->orderBy('sort_order')->get()
            : $this->defaultPersonas();

        $usable = $personas->filter(
            fn (Persona $p) => $p->aiModel && $p->aiModel->provider && filled($p->aiModel->provider->api_key)
        );

        if (! $json) {
            foreach ($personas->diff($usable) as $skipped) {
                $this->warn("Preskačem '{$skipped->name}' — provider nema API ključ.");
            }
        }

        $fast = (bool) $this->option('fast');

        $chat = $user->chats()->create([
            'title' => $this->option('title') ?: 'CLI rasprava',
            'rounds_per_turn' => $fast ? 1 : max(1, min(200, (int) $this->option('rounds'))),
            'scribe_interval' => $fast ? 1000000 : max(1, (int) $this->option('scribe')),
            'status' => Chat::STATUS_ACTIVE,
        ]);

        foreach ($usable as $persona) {
            $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);
        }

        return $chat;
    }

    private function defaultPersonas(): Collection
    {
        return Persona::query()
            ->where('is_active', true)
            ->where('is_scribe', false)
            ->whereHas('aiModel.provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('aiModel.provider')
            ->get()
            ->sortBy(fn (Persona $p) => (float) $p->aiModel->input_cost_per_1m_tokens)
            ->take(5)
            ->values();
    }
}
