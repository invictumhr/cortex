<?php

/**
 * Runs cortex:benchmark for every question in questions.json and writes the
 * results to results.json incrementally (safe to interrupt and resume).
 *
 * Usage: php benchmark/run.php
 */

set_time_limit(0);

$base = __DIR__;
$questionsPath = $base.'/questions.json';
$resultsPath = $base.'/results.json';
$logPath = $base.'/run.log';

$questions = json_decode((string) file_get_contents($questionsPath), true);
if (! is_array($questions)) {
    fwrite(STDERR, "Failed to load questions.json\n");
    exit(1);
}

$results = [];
if (file_exists($resultsPath)) {
    $existing = json_decode((string) file_get_contents($resultsPath), true);
    if (is_array($existing)) {
        $results = $existing;
    }
}
$done = array_column($results, 'id');

$log = fopen($logPath, 'a');
$writeLog = function (string $line) use ($log): void {
    $stamp = date('H:i:s');
    fwrite($log, "[{$stamp}] {$line}\n");
    fflush($log);
    fwrite(STDERR, "[{$stamp}] {$line}\n");
};

$writeLog(sprintf('starting; %d question(s), %d already done', count($questions), count($done)));
$startTime = microtime(true);

// 5 EN-friendly personas (no HR-hardcoded ones) for consistent English output.
$personas = 'viktor,helena,ana,petra,marco';

foreach ($questions as $i => $q) {
    $idx = $i + 1;
    if (in_array($q['id'], $done, true)) {
        $writeLog(sprintf('[%d/%d] q%d already done — skip', $idx, count($questions), $q['id']));
        continue;
    }

    $writeLog(sprintf('[%d/%d] q%d: %s', $idx, count($questions), $q['id'], substr($q['question'], 0, 90)));

    $started = microtime(true);
    $cmd = sprintf(
        'php "%s/../artisan" cortex:benchmark %s --personas=%s --rounds=2 --language=en --json',
        $base,
        escapeshellarg($q['question']),
        $personas
    );
    $output = shell_exec($cmd);
    $elapsed = round(microtime(true) - $started, 1);

    $parsed = json_decode((string) $output, true);
    $ok = is_array($parsed) && (($parsed['ok'] ?? false) === true);

    $results[] = [
        'id' => $q['id'],
        'category' => $q['category'],
        'question' => $q['question'],
        'elapsed_seconds' => $elapsed,
        'ok' => $ok,
        'winner' => $parsed['verdict']['winner'] ?? null,
        'score_single' => $parsed['verdict']['score_single'] ?? null,
        'score_boardroom' => $parsed['verdict']['score_boardroom'] ?? null,
        'cost_eur_boardroom' => $parsed['boardroom']['cost_eur'] ?? null,
        'cost_eur_single' => $parsed['single_model']['cost_eur'] ?? null,
        'control_model' => $parsed['single_model']['model'] ?? null,
        'verdict_reason' => $parsed['verdict']['reason'] ?? null,
        'boardroom_answer' => $parsed['boardroom']['answer'] ?? null,
        'single_answer' => $parsed['single_model']['answer'] ?? null,
        'raw' => $parsed,
    ];

    file_put_contents(
        $resultsPath,
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $totalElapsed = microtime(true) - $startTime;
    $doneNow = count($results) - count($done);
    $remainingCount = count($questions) - count($results);
    $eta = $doneNow > 0 ? round(($totalElapsed / $doneNow) * $remainingCount) : 0;

    $writeLog(sprintf(
        '  -> winner=%s, %.1fs, cost B=%.4f S=%.4f, eta %ds',
        $parsed['verdict']['winner'] ?? '?',
        $elapsed,
        (float) ($parsed['boardroom']['cost_eur'] ?? 0),
        (float) ($parsed['single_model']['cost_eur'] ?? 0),
        $eta
    ));
}

$writeLog('DONE');
fclose($log);
