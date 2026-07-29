<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use App\Services\Chat\ChatOrchestrator;
use App\Services\LanguageDetector;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Benchmark the boardroom against a single strong model on the same topic,
 * then have a neutral model judge both blind. Validates whether the
 * multi-persona panel actually beats one model.
 */
class CortexBenchmark extends Command
{
    protected $signature = 'cortex:benchmark
        {message : Tema za usporedbu}
        {--personas= : Slugovi persona za boardroom (izostavljeno => 5 zadanih)}
        {--rounds=2 : Krugova rasprave}
        {--model= : Model jednomodelne kontrole (default Opus 5)}
        {--language=en : Jezik (ISO 639-1, default en; podržano: en, hr, sr, bs, sl, sk, cs, pl, bg, ru, uk, de, fr, it, es, pt, nl, ro, hu, sv, el, da, fi)}
        {--json : Strojno-čitljiv JSON izlaz}';

    protected $description = 'Usporedi boardroom protiv jednog jakog modela na istoj temi';

    /** Detected conversation language — keeps boardroom, control and judge consistent. */
    private string $language = 'Croatian';

    public function handle(ChatOrchestrator $orchestrator, AiProviderFactory $factory): int
    {
        config(['queue.default' => 'sync', 'broadcasting.default' => 'log']);

        $json = (bool) $this->option('json');
        $topic = trim((string) $this->argument('message'));

        if ($topic === '') {
            return $this->bail('Navedi temu.', $json);
        }

        $languageOption = strtolower(trim((string) $this->option('language')));
        if ($languageOption !== '' && ! in_array($languageOption, LanguageDetector::supportedIsoCodes(), true)) {
            return $this->bail(
                'Language not supported: '.$languageOption
                .'. Supported ISO codes: '.implode(', ', LanguageDetector::supportedIsoCodes()),
                $json,
            );
        }

        $this->language = LanguageDetector::fromIso($languageOption);

        $user = User::first();
        if (! $user) {
            return $this->bail('Nema korisnika. Pokreni: php artisan db:seed', $json);
        }

        if (! $json) {
            $this->comment('1/3 Boardroom rasprava...');
        }
        $boardroom = $this->runBoardroom($orchestrator, $user, $topic);
        if ($boardroom === null) {
            return $this->bail('Boardroom rasprava nije uspjela.', $json);
        }

        if (! $json) {
            $this->comment('2/3 Jednomodelna kontrola...');
        }
        $control = $this->runControl($factory, $topic);
        if ($control === null) {
            return $this->bail('Kontrolni model nije dostupan.', $json);
        }

        if (! $json) {
            $this->comment('3/3 Slijepa ocjena...');
        }
        $verdict = $this->evaluate($factory, $topic, $control['answer'], $boardroom['answer']);

        return $this->report($json, $topic, $control, $boardroom, $verdict);
    }

    /**
     * @return array{chat_id: int, answer: string, cost: float, elapsed: float}|null
     */
    private function runBoardroom(ChatOrchestrator $orchestrator, User $user, string $topic): ?array
    {
        $slugs = array_filter(array_map('trim', explode(',', (string) $this->option('personas'))));

        $query = Persona::query()
            ->where('is_scribe', false)
            ->where('is_chair', false)
            ->where('is_ephemeral', false)
            ->with('aiModel.provider');

        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        } else {
            $query->where('is_active', true)->orderBy('sort_order');
        }

        $personas = $query->get()
            ->filter(fn (Persona $p) => $p->aiModel && filled($p->aiModel->provider?->api_key))
            ->take(5);

        if ($personas->isEmpty()) {
            return null;
        }

        $chat = $user->chats()->create([
            'title' => 'Benchmark: '.mb_substr($topic, 0, 60),
            'rounds_per_turn' => max(1, min(20, (int) $this->option('rounds'))),
            'scribe_interval' => (int) config('cortex.default_scribe_interval', 50),
            'language' => $this->language,
            'status' => Chat::STATUS_ACTIVE,
        ]);

        foreach ($personas as $persona) {
            $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);
        }

        $startedAt = microtime(true);

        try {
            $orchestrator->sendUserMessage($chat, $user, $topic);
        } catch (Throwable) {
            return null;
        }

        $chat->refresh();

        $chair = $chat->messages()
            ->where('role', ChatMessage::ROLE_PERSONA)
            ->orderByDesc('id')
            ->get()
            ->first(fn (ChatMessage $m) => (bool) ($m->metadata['chair_decision'] ?? false));

        // The boardroom's deliverable is the scribe's full synthesis plus the
        // Chair's decision — not the terse decision alone.
        $parts = array_filter([
            $chat->latestScribeSummary()?->summary,
            $chair?->content,
        ], static fn ($part) => filled($part));

        $answer = $parts !== []
            ? implode("\n\n", array_map('trim', $parts))
            : '(boardroom nije dao zaključak)';

        return [
            'chat_id' => $chat->id,
            'answer' => trim((string) $answer),
            'cost' => (float) $chat->total_cost,
            'elapsed' => round(microtime(true) - $startedAt, 1),
        ];
    }

    /**
     * @return array{model: string, answer: string, cost: float, elapsed: float}|null
     */
    private function runControl(AiProviderFactory $factory, string $topic): ?array
    {
        $modelString = trim((string) $this->option('model'))
            ?: (string) config('cortex.benchmark_control_model', 'claude-opus-5');

        $model = AiModel::query()
            ->where('model_string', $modelString)
            ->where('is_active', true)
            ->with('provider')
            ->first();

        if (! $model || blank($model->provider?->api_key)) {
            return null;
        }

        $startedAt = microtime(true);

        try {
            $response = $factory->for($model)->sendMessage(
                'Ti si vrhunski stručnjak. Odgovori na pitanje temeljito i konkretno: iznesi preporuku, ključne rizike '
                .'i trade-offove. Piši na jeziku: '.$this->language.'.',
                [AiMessage::user($topic)],
                ['max_tokens' => 1500, 'temperature' => 0.5],
            );
        } catch (Throwable) {
            return null;
        }

        return [
            'model' => $model->model_string,
            'answer' => trim((string) $response->content),
            'cost' => $model->calculateCost($response->billableInputTokens(), $response->outputTokens),
            'elapsed' => round(microtime(true) - $startedAt, 1),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluate(AiProviderFactory $factory, string $topic, string $single, string $boardroom): ?array
    {
        $model = AiModel::query()
            ->where('model_string', (string) config('cortex.benchmark_evaluator_model', 'claude-sonnet-4-6'))
            ->where('is_active', true)
            ->with('provider')
            ->first();

        if (! $model || blank($model->provider?->api_key)) {
            return null;
        }

        // Random A/B order so the judge cannot infer which answer is the boardroom.
        $swap = random_int(0, 1) === 1;
        $first = $swap ? $boardroom : $single;
        $second = $swap ? $single : $boardroom;

        try {
            $response = $factory->for($model)->sendMessage(
                'Ti si neutralni ocjenjivač. Dobivaš dva odgovora na isto pitanje. Ocijeni koji bolje iznosi prave '
                .'napetosti, trade-offove, rizike i ne-očite kutove — NE nagrađuj duljinu ni samouvjerenost. '
                .'Vrati ISKLJUČIVO JSON: {"winner": 1 ili 2, "score_1": 1-10, "score_2": 1-10, "reason": "3-4 rečenice"}. '
                .'"reason" napiši na jeziku: '.$this->language.'.',
                [AiMessage::user("PITANJE:\n".$topic."\n\nODGOVOR 1:\n".$first."\n\nODGOVOR 2:\n".$second)],
                ['max_tokens' => 600, 'temperature' => 0],
            );
        } catch (Throwable) {
            return null;
        }

        if (! preg_match('/\{.*\}/s', (string) $response->content, $matched)) {
            return ['raw' => trim((string) $response->content)];
        }

        $decoded = json_decode($matched[0], true);
        if (! is_array($decoded)) {
            return ['raw' => trim((string) $response->content)];
        }

        $winnerSlot = (int) ($decoded['winner'] ?? 0);
        $scoreFirst = (int) ($decoded['score_1'] ?? 0);
        $scoreSecond = (int) ($decoded['score_2'] ?? 0);

        return [
            'winner' => match ($winnerSlot) {
                1 => $swap ? 'boardroom' : 'jedan model',
                2 => $swap ? 'jedan model' : 'boardroom',
                default => 'neodlučeno',
            },
            'score_single' => $swap ? $scoreSecond : $scoreFirst,
            'score_boardroom' => $swap ? $scoreFirst : $scoreSecond,
            'reason' => trim((string) ($decoded['reason'] ?? '')),
        ];
    }

    /**
     * @param  array{model: string, answer: string, cost: float, elapsed: float}  $control
     * @param  array{chat_id: int, answer: string, cost: float, elapsed: float}  $boardroom
     * @param  array<string, mixed>|null  $verdict
     */
    private function report(bool $json, string $topic, array $control, array $boardroom, ?array $verdict): int
    {
        if ($json) {
            $this->output->writeln(json_encode([
                'ok' => true,
                'version' => config('cortex.api_version'),
                'topic' => $topic,
                'single_model' => [
                    'model' => $control['model'],
                    'answer' => $control['answer'],
                    'cost_eur' => round($control['cost'], 6),
                    'elapsed_seconds' => $control['elapsed'],
                ],
                'boardroom' => [
                    'chat_id' => $boardroom['chat_id'],
                    'answer' => $boardroom['answer'],
                    'cost_eur' => round($boardroom['cost'], 6),
                    'elapsed_seconds' => $boardroom['elapsed'],
                ],
                'verdict' => $verdict,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>BENCHMARK:</> '.$topic);
        $this->newLine();
        $this->line('<options=bold;fg=cyan>JEDAN MODEL ('.$control['model'].')</> — €'
            .number_format($control['cost'], 4).' · '.$control['elapsed'].'s');
        $this->line(trim($control['answer']));
        $this->newLine();
        $this->line('<options=bold;fg=yellow>BOARDROOM (chat #'.$boardroom['chat_id'].')</> — €'
            .number_format($boardroom['cost'], 4).' · '.$boardroom['elapsed'].'s');
        $this->line(trim($boardroom['answer']));
        $this->newLine();

        if (is_array($verdict) && isset($verdict['winner'])) {
            $this->line('<options=bold;fg=green>PRESUDA:</> pobjednik = '.$verdict['winner']
                .'  (jedan model '.$verdict['score_single'].'/10 · boardroom '.$verdict['score_boardroom'].'/10)');
            $this->line(trim((string) $verdict['reason']));
        } elseif (is_array($verdict) && isset($verdict['raw'])) {
            $this->line('<options=bold;fg=green>PRESUDA:</>');
            $this->line(trim((string) $verdict['raw']));
        } else {
            $this->warn('Ocjenjivač nije vratio presudu.');
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
