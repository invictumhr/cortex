<?php

namespace App\Console\Commands;

use App\Events\ChatMessageCreated;
use App\Events\PersonaIsTyping;
use App\Events\RoundCompleted;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\CostEstimator;
use App\Services\Chat\KnowledgeService;
use App\Services\Chat\PanelArchitect;
use App\Services\LanguageDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
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
        {--context= : Putanja do datoteke s kontekstom (data model, postojeće stanje)}
        {--constraints= : Tvrda ograničenja koja sve persone moraju poštovati}
        {--architect : Model dizajnira panel uloga skrojen za pitanje (umjesto fiksnih persona)}
        {--strong : Panel trči na flagship modelima (Opus 4.7, o3, Grok 3, Gemini Pro...)}
        {--language=en : Jezik rasprave (ISO 639-1, default en; podržano: en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk, de, fr, it, es, pt, nl, ro, hu, sv, el, da, fi)}
        {--json : Strojno-čitljiv JSON izlaz za druge agente}';

    protected $description = 'Pokreni ili nastavi Cortex boardroom raspravu iz CLI-ja';

    /** IDs of messages already streamed live, so the final dump skips them. */
    private array $streamedIds = [];

    public function handle(ChatOrchestrator $orchestrator, KnowledgeService $knowledge): int
    {
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $json = (bool) $this->option('json');
        $topic = trim((string) $this->argument('message'));

        if ($topic === '' || in_array(mb_strtolower($topic), ['help', '?'], true)) {
            $this->showUsage($json);

            return self::SUCCESS;
        }

        $languageOption = strtolower(trim((string) $this->option('language')));
        if ($languageOption !== '' && ! in_array($languageOption, LanguageDetector::supportedIsoCodes(), true)) {
            return $this->bail(
                'Language not supported: '.$languageOption
                .'. Supported ISO codes: '.implode(', ', LanguageDetector::supportedIsoCodes()),
                $json,
            );
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

        $contextText = $this->resolveContextOption($json);
        if ($contextText === false) {
            return self::FAILURE;
        }

        $chat = $this->option('chat')
            ? Chat::find((int) $this->option('chat'))
            : $this->createChat($user, $json, $topic, $contextText, trim((string) $this->option('constraints')));

        if (! $chat) {
            return $this->bail('Chat nije pronađen.', $json);
        }

        // Pinned context/constraints — applied to new and continued chats alike.
        $applied = array_filter(
            ['context' => $contextText, 'constraints' => trim((string) $this->option('constraints'))],
            fn (string $value) => $value !== '',
        );
        if ($applied !== []) {
            $chat->update($applied);
        }

        if ($this->option('strong') && ! $chat->strong) {
            $chat->update(['strong' => true]);
        }

        $speakers = $chat->activePersonas()->where('is_scribe', false)->orderBy('sort_order')->get();
        if ($speakers->isEmpty()) {
            return $this->bail('Chat nema aktivnih persona — provjeri API ključeve.', $json);
        }

        $beforeId = (int) ($chat->messages()->max('id') ?? 0);

        $estimate = app(CostEstimator::class)->estimate($chat);

        if (! $json) {
            $this->info("=== Chat #{$chat->id}: {$chat->title} ===");
            $this->line('Persone: '.$speakers->pluck('name')->implode(', ')." | Krugova: {$chat->rounds_per_turn}");
            $this->newLine();
            $this->line('<options=bold;fg=blue>TI:</> '.$topic);
            $this->newLine();

            $this->line(sprintf(
                '<options=bold;fg=yellow>Procjena troška:</> ~€%s  <fg=gray>(raspon €%s–€%s · %d persona × %d krug.)</>',
                number_format($estimate['expected'], 4),
                number_format($estimate['low'], 4),
                number_format($estimate['high'], 4),
                $estimate['speakers'],
                $estimate['rounds'],
            ));

            $this->newLine();
            $this->comment('Generiram raspravu...');

            $this->registerLiveOutput($chat);
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
                'strong' => (bool) $chat->strong,
                'elapsed_seconds' => $elapsed,
                'cost_eur' => round((float) $chat->total_cost, 6),
                'cost_estimate' => [
                    'expected_eur' => $estimate['expected'],
                    'low_eur' => $estimate['low'],
                    'high_eur' => $estimate['high'],
                ],
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
                'decision' => $new->first(fn ($m) => (bool) ($m->metadata['chair_decision'] ?? false))?->content,
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

        // Anything not already streamed live (e.g. a missed event) is printed now.
        foreach ($new as $m) {
            if (! in_array($m->id, $this->streamedIds, true)) {
                $this->renderMessage($m);
            }
        }
        $this->newLine();

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

    /**
     * Stream persona/scribe/Chair messages to the console as they are produced,
     * so a CLI run shows live progress instead of a silent wait then a dump.
     */
    private function registerLiveOutput(Chat $chat): void
    {
        Event::listen(ChatMessageCreated::class, function (ChatMessageCreated $event) use ($chat): void {
            $message = $event->message;

            if ($message->chat_id !== $chat->id
                || $message->role === ChatMessage::ROLE_USER
                || in_array($message->id, $this->streamedIds, true)) {
                return;
            }

            $this->streamedIds[] = $message->id;
            $this->renderMessage($message);
        });

        Event::listen(PersonaIsTyping::class, function (PersonaIsTyping $event) use ($chat): void {
            if ($event->chatId === $chat->id) {
                $this->line("  <fg=gray>· {$event->personaName} razmišlja… (krug {$event->round})</>");
            }
        });

        Event::listen(RoundCompleted::class, function (RoundCompleted $event) use ($chat): void {
            if ($event->chat->id === $chat->id
                && $event->round >= (int) $chat->rounds_per_turn
                && (int) $chat->scribe_interval < 1_000_000) {
                $this->line('  <fg=gray>· Scribe sastavlja završnu sintezu, Chair presuđuje…</>');
            }
        });
    }

    /**
     * Render one boardroom message as a labelled console block.
     */
    private function renderMessage(ChatMessage $message): void
    {
        $who = match ($message->role) {
            ChatMessage::ROLE_SCRIBE => '📝 SCRIBE',
            ChatMessage::ROLE_SYSTEM => '⚙ SUSTAV',
            default => trim(($message->persona?->avatar_emoji ?? '').' '.($message->persona?->name ?? 'Persona')),
        };

        $this->newLine();
        $this->line("<options=bold;fg=cyan>[krug {$message->round_number}] {$who}</>");
        $this->line(trim((string) $message->content));
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
                    '--personas' => 'Slugovi persona, zarezom odvojeni. Izostavljeno => router bira 5 po domeni teme.',
                    '--rounds' => 'Broj krugova rasprave 1-200 (default 2). Krug 1 je neovisan, krug 2+ je rasprava.',
                    '--scribe' => 'Prag poruka za scribe sažetak (default 50).',
                    '--fast' => 'Brzi način: 1 krug, bez scribe sažetka.',
                    '--memory' => 'Ubaci akumulirano globalno znanje (knowledge digest) u kontekst rasprave.',
                    '--context' => 'Putanja do datoteke s kontekstom (data model, stanje) — personama trajno vidljiva.',
                    '--constraints' => 'Tvrda ograničenja koja nijedan prijedlog ne smije kršiti.',
                    '--architect' => 'Model dizajnira panel uloga skrojen za pitanje umjesto fiksnih persona.',
                    '--strong' => 'Panel trči na flagship modelima svakog providera (skuplje, kvalitetnije).',
                    '--title' => 'Naslov rasprave kod novog chata.',
                    '--chat' => 'ID postojeće rasprave — nastavlja je uz sačuvani kontekst.',
                    '--json' => 'Strojno-čitljiv JSON izlaz.',
                ],
                'persona_slugs' => Persona::query()->where('is_scribe', false)->where('is_chair', false)->where('is_ephemeral', false)->orderBy('sort_order')->pluck('slug')->all(),
                'json_output_fields' => ['ok', 'version', 'chat_id', 'cost_eur', 'cost_estimate', 'input_tokens', 'output_tokens', 'speakers', 'turn_messages', 'scribe_summary', 'decision', 'scribe'],
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
            '    --context=datoteka     Priloži kontekst (data model, stanje) — personama trajno vidljiv',
            '    --constraints="..."    Tvrda ograničenja koja nijedan prijedlog ne smije kršiti',
            '    --architect            Model dizajnira panel uloga skrojen za pitanje',
            '    --strong               Panel na flagship modelima svakog providera (skuplje, jače)',
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

    private function createChat(User $user, bool $json, string $topic, string $context, string $constraints): Chat
    {
        $fast = (bool) $this->option('fast');

        $chat = $user->chats()->create([
            'title' => $this->option('title') ?: 'CLI rasprava',
            'context' => $context !== '' ? $context : null,
            'constraints' => $constraints !== '' ? $constraints : null,
            'strong' => (bool) $this->option('strong'),
            'rounds_per_turn' => $fast ? 1 : max(1, min(200, (int) $this->option('rounds'))),
            'scribe_interval' => $fast ? 1000000 : max(1, (int) $this->option('scribe')),
            'language' => LanguageDetector::fromIso((string) $this->option('language')),
            'status' => Chat::STATUS_ACTIVE,
        ]);

        $slugs = array_filter(array_map('trim', explode(',', (string) $this->option('personas'))));

        if ($slugs !== []) {
            $personas = Persona::whereIn('slug', $slugs)
                ->where('is_scribe', false)->where('is_chair', false)->where('is_ephemeral', false)
                ->with('aiModel.provider')->orderBy('sort_order')->get();
        } elseif ($this->option('architect')) {
            $personas = app(PanelArchitect::class)->design($chat, $topic);
            if ($personas->isEmpty()) {
                $personas = $this->defaultPersonas($topic); // architect zakazao -> router fallback
            }
        } else {
            $personas = $this->defaultPersonas($topic);
        }

        $usable = $personas->filter(
            fn (Persona $p) => $p->aiModel && $p->aiModel->provider && filled($p->aiModel->provider->api_key)
        );

        if (! $json) {
            foreach ($personas->diff($usable) as $skipped) {
                $this->warn("Preskačem '{$skipped->name}' — provider nema API ključ.");
            }
        }

        foreach ($usable as $persona) {
            $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);
        }

        return $chat;
    }

    /**
     * Auto-pick a 5-persona panel: a cheap router model matches the topic to
     * personas by domain, and the Realist is always seated for feasibility.
     */
    private function defaultPersonas(string $topic): Collection
    {
        $pool = Persona::query()
            ->where('is_active', true)
            ->where('is_scribe', false)
            ->where('is_chair', false)
            ->where('is_ephemeral', false)
            ->whereHas('aiModel.provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('aiModel.provider')
            ->get();

        if ($pool->isEmpty()) {
            return $pool;
        }

        $picked = $this->routePersonas($topic, $pool);

        // Top up (or fully fall back) with the cheapest unused personas.
        foreach ($pool->sortBy(fn (Persona $p) => (float) $p->aiModel->input_cost_per_1m_tokens) as $persona) {
            if ($picked->count() >= 5) {
                break;
            }
            if (! $picked->contains('id', $persona->id)) {
                $picked->push($persona);
            }
        }

        $picked = $picked->take(5);

        // Always seat the Realist so a feasibility check is guaranteed.
        $realist = $pool->firstWhere('slug', 'realist');
        if ($realist && ! $picked->contains('id', $realist->id)) {
            $picked = $picked->take(4)->push($realist);
        }

        return $picked->values();
    }

    /**
     * Ask a cheap router model which 5 personas best fit the topic by domain.
     *
     * @param  Collection<int, Persona>  $pool
     * @return Collection<int, Persona>
     */
    private function routePersonas(string $topic, Collection $pool): Collection
    {
        try {
            $model = AiModel::query()
                ->where('model_string', config('cortex.router_model', 'gpt-4o-mini'))
                ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
                ->with('provider')
                ->first();

            if (! $model) {
                return collect();
            }

            $roster = $pool
                ->map(fn (Persona $p) => $p->slug.' — '.$p->title.' ['.implode(', ', (array) $p->expertise_areas).']')
                ->implode("\n");

            $response = app(AiProviderFactory::class)->for($model)->sendMessage(
                'Ti si router za panel stručnjaka. Iz popisa odaberi 5 stručnjaka čije se područje najbolje preklapa s temom. '
                .'Vrati ISKLJUČIVO JSON niz od 5 slugova, npr. ["marco","ana","zara","helena","rex"].',
                [AiMessage::user("TEMA:\n".$topic."\n\nSTRUČNJACI:\n".$roster)],
                ['max_tokens' => 120, 'temperature' => 0],
            );

            preg_match('/\[[^\]]*\]/s', (string) $response->content, $matched);
            $slugs = $matched ? array_filter((array) json_decode($matched[0], true), 'is_string') : [];

            return $pool->whereIn('slug', $slugs)->values();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Read the --context file, or return '' when not given / false on error.
     */
    private function resolveContextOption(bool $json): string|false
    {
        $path = trim((string) $this->option('context'));

        if ($path === '') {
            return '';
        }

        if (! is_file($path)) {
            $this->bail("Kontekst datoteka nije pronađena: {$path}", $json);

            return false;
        }

        return (string) file_get_contents($path);
    }
}
