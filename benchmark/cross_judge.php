<?php

/**
 * Re-judges every question in results.json with a SECOND judge model, writes
 * verdicts to a separate file. This is the cross-check that the win/loss
 * pattern is not an artefact of a single judge (e.g. a Claude judge having a
 * stylistic preference for the Claude-family single-model control).
 *
 * Usage:
 *   php benchmark/cross_judge.php                       # judge = gpt-4o (default)
 *   php benchmark/cross_judge.php --judge=gpt-4o
 *   php benchmark/cross_judge.php --judge=gemini-2.5-pro
 *
 * Output: benchmark/verdicts_{judge}.json
 */

set_time_limit(0);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiModel;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;

$argMap = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $argMap[$k] = $v;
    }
}

$judgeString = $argMap['judge'] ?? 'gpt-4o';
$slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($judgeString));
$outputPath = __DIR__.'/verdicts_'.$slug.'.json';
$resultsPath = __DIR__.'/results.json';

$results = json_decode((string) file_get_contents($resultsPath), true);
if (! is_array($results)) {
    echo "No results.json or it is empty.\n";
    exit(1);
}

$judge = AiModel::query()
    ->where('model_string', $judgeString)
    ->where('is_active', true)
    ->with('provider')
    ->first();

if (! $judge) {
    echo "Judge model {$judgeString} not found / not active.\n";
    exit(1);
}

$verdicts = [];
if (file_exists($outputPath)) {
    $existing = json_decode((string) file_get_contents($outputPath), true);
    if (is_array($existing)) {
        $verdicts = $existing;
    }
}
$doneIds = array_column($verdicts, 'id');

$factory = app(AiProviderFactory::class);
$judged = 0;
$skipped = 0;
$started = microtime(true);

foreach ($results as $row) {
    if (in_array($row['id'], $doneIds, true)) {
        $skipped++;
        continue;
    }

    $topic = $row['question'] ?? '';
    $boardroom = (string) ($row['boardroom_answer'] ?? '');
    $single = (string) ($row['single_answer'] ?? '');

    if ($boardroom === '' || $single === '' || $topic === '') {
        echo "q{$row['id']}: skip (missing answers)\n";
        continue;
    }

    // Random A/B order so the judge cannot infer which side is the boardroom.
    $swap = random_int(0, 1) === 1;
    $first = $swap ? $boardroom : $single;
    $second = $swap ? $single : $boardroom;

    echo "q{$row['id']}: judging with {$judgeString}… ";
    flush();

    try {
        $response = $factory->for($judge)->sendMessage(
            'You are a neutral judge. You receive two answers to the same question. '
            .'Decide which one better surfaces real tensions, trade-offs, risks and non-obvious angles. '
            .'Do NOT reward length or confidence. '
            .'Return ONLY a JSON object: {"winner": 1 or 2, "score_1": 1-10, "score_2": 1-10, "reason": "3-4 sentences"}.',
            [AiMessage::user("QUESTION:\n".$topic."\n\nANSWER 1:\n".$first."\n\nANSWER 2:\n".$second)],
            ['max_tokens' => 1500, 'temperature' => 0],
        );
    } catch (Throwable $e) {
        echo "FAILED: {$e->getMessage()}\n";
        continue;
    }

    $content = trim((string) $response->content);

    if (! preg_match('/\{[\s\S]*\}/', $content, $m)) {
        echo "no JSON (".mb_strlen($content)." chars)\n";
        continue;
    }

    $decoded = json_decode($m[0], true);
    if (! is_array($decoded)) {
        echo "JSON parse failed\n";
        continue;
    }

    $slot = (int) ($decoded['winner'] ?? 0);
    $scoreFirst = (int) ($decoded['score_1'] ?? 0);
    $scoreSecond = (int) ($decoded['score_2'] ?? 0);

    $winner = match ($slot) {
        1 => $swap ? 'boardroom' : 'single model',
        2 => $swap ? 'single model' : 'boardroom',
        default => 'tie',
    };

    $verdicts[] = [
        'id' => $row['id'],
        'category' => $row['category'] ?? null,
        'judge' => $judgeString,
        'winner' => $winner,
        'score_boardroom' => $swap ? $scoreFirst : $scoreSecond,
        'score_single' => $swap ? $scoreSecond : $scoreFirst,
        'reason' => trim((string) ($decoded['reason'] ?? '')),
    ];

    file_put_contents(
        $outputPath,
        json_encode($verdicts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "winner={$winner} ({$verdicts[count($verdicts)-1]['score_boardroom']} vs {$verdicts[count($verdicts)-1]['score_single']})\n";
    $judged++;
}

$totalTime = round(microtime(true) - $started, 1);
echo "\nDone. Judged {$judged}, skipped {$skipped}. {$totalTime}s. Output: {$outputPath}\n";
