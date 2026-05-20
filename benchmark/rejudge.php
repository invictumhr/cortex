<?php

/**
 * Re-judge any benchmark question in results.json whose verdict is missing or
 * empty (e.g. because the original judge model returned nothing). Reads the
 * boardroom + single-model answers already captured and asks the configured
 * judge model again, blind A/B-randomised.
 *
 * Usage: php benchmark/rejudge.php
 */

set_time_limit(0);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiModel;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;

$resultsPath = __DIR__.'/results.json';
$results = json_decode((string) file_get_contents($resultsPath), true);

if (! is_array($results)) {
    echo "No results.json or it is empty.\n";
    exit(1);
}

$judgeModelString = (string) config('cortex.benchmark_evaluator_model', 'claude-sonnet-4-6');

$judge = AiModel::query()
    ->where('model_string', $judgeModelString)
    ->where('is_active', true)
    ->with('provider')
    ->first();

if (! $judge) {
    echo "Judge model {$judgeModelString} not found / not active.\n";
    exit(1);
}

$factory = app(AiProviderFactory::class);
$rejudged = 0;
$skipped = 0;

foreach ($results as $i => $row) {
    $verdict = $row['winner'] ?? null;
    $hasReal = is_string($verdict) && $verdict !== ''
        && in_array($verdict, ['boardroom', 'single model', 'jedan model', 'tie', 'neodlučeno'], true);

    if ($hasReal) {
        $skipped++;
        continue;
    }

    $topic = $row['question'] ?? '';
    $boardroom = (string) ($row['boardroom_answer'] ?? '');
    $single = (string) ($row['single_answer'] ?? '');

    if ($boardroom === '' || $single === '' || $topic === '') {
        echo "q{$row['id']}: skip (missing answers / topic)\n";
        continue;
    }

    // Random A/B order so the judge cannot infer which is the boardroom.
    $swap = random_int(0, 1) === 1;
    $first = $swap ? $boardroom : $single;
    $second = $swap ? $single : $boardroom;

    echo "q{$row['id']}: re-judging with {$judgeModelString}… ";
    flush();

    try {
        $response = $factory->for($judge)->sendMessage(
            'You are a neutral judge. You receive two answers to the same question. Decide which one better surfaces real tensions, trade-offs, risks and non-obvious angles. Do NOT reward length or confidence. '
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
        echo "no JSON in response (".mb_strlen($content)." chars)\n";
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

    $row['winner'] = match ($slot) {
        1 => $swap ? 'boardroom' : 'single model',
        2 => $swap ? 'single model' : 'boardroom',
        default => 'tie',
    };
    $row['score_boardroom'] = $swap ? $scoreFirst : $scoreSecond;
    $row['score_single'] = $swap ? $scoreSecond : $scoreFirst;
    $row['verdict_reason'] = trim((string) ($decoded['reason'] ?? ''));
    $row['rejudged_by'] = $judgeModelString;

    $results[$i] = $row;
    file_put_contents(
        $resultsPath,
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    echo "winner={$row['winner']} ({$row['score_boardroom']} vs {$row['score_single']})\n";
    $rejudged++;
}

echo "\nDone. Re-judged {$rejudged}, skipped {$skipped} (already had verdicts).\n";
