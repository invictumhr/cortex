<?php

/**
 * Consolidates verdicts from sonnet (in results.json) and gpt-4o
 * (verdicts_gpt_4o.json) and prints headline stats — win rates per judge,
 * inter-judge agreement, per-category breakdown, cost and time.
 */

$base = __DIR__;

$results = json_decode((string) file_get_contents($base.'/results.json'), true);
$gpt = json_decode((string) file_get_contents($base.'/verdicts_gpt_4o.json'), true);
$gptById = array_column($gpt, null, 'id');

$normalise = static fn (?string $w): ?string => match ($w) {
    'jedan model', 'single model' => 'single model',
    'boardroom' => 'boardroom',
    'tie', 'neodlučeno' => 'tie',
    default => null,
};

$rows = [];
foreach ($results as $r) {
    $s = $normalise($r['winner'] ?? null);
    $g = $normalise($gptById[$r['id']]['winner'] ?? null);
    $rows[] = [
        'id' => $r['id'],
        'category' => $r['category'],
        'question' => $r['question'],
        'sonnet' => $s,
        'gpt' => $g,
        'agree' => $s !== null && $g !== null && $s === $g,
        'sonnet_b' => $r['score_boardroom'] ?? null,
        'sonnet_s' => $r['score_single'] ?? null,
        'gpt_b' => $gptById[$r['id']]['score_boardroom'] ?? null,
        'gpt_s' => $gptById[$r['id']]['score_single'] ?? null,
        'cost_b' => (float) ($r['cost_eur_boardroom'] ?? 0),
        'cost_s' => (float) ($r['cost_eur_single'] ?? 0),
        'elapsed' => (float) ($r['elapsed_seconds'] ?? 0),
    ];
}

$total = count($rows);
$sonnetB = $gptB = $agreedB = $agreedS = $disagree = $tie = 0;
$costB = $costS = $time = 0;
$byCat = [];

foreach ($rows as $r) {
    if ($r['sonnet'] === 'boardroom') $sonnetB++;
    if ($r['gpt'] === 'boardroom') $gptB++;
    if ($r['agree']) {
        if ($r['sonnet'] === 'boardroom') $agreedB++;
        elseif ($r['sonnet'] === 'single model') $agreedS++;
        elseif ($r['sonnet'] === 'tie') $tie++;
    } elseif ($r['sonnet'] !== null && $r['gpt'] !== null) {
        $disagree++;
    }
    $costB += $r['cost_b']; $costS += $r['cost_s']; $time += $r['elapsed'];
    $c = $r['category'];
    if (! isset($byCat[$c])) $byCat[$c] = ['n'=>0, 'sB'=>0, 'gB'=>0, 'agreeB'=>0, 'agreeS'=>0];
    $byCat[$c]['n']++;
    if ($r['sonnet'] === 'boardroom') $byCat[$c]['sB']++;
    if ($r['gpt'] === 'boardroom') $byCat[$c]['gB']++;
    if ($r['agree']) {
        if ($r['sonnet'] === 'boardroom') $byCat[$c]['agreeB']++;
        elseif ($r['sonnet'] === 'single model') $byCat[$c]['agreeS']++;
    }
}

$pct = fn ($a, $b) => $b ? round($a / $b * 100, 1) : 0;

echo "=== HEADLINE ===\n";
echo "Total questions: {$total}\n";
echo "Sonnet (Claude) judge — boardroom wins: {$sonnetB}/{$total} = ".$pct($sonnetB, $total)."%\n";
echo "GPT-4o (OpenAI) judge — boardroom wins: {$gptB}/{$total} = ".$pct($gptB, $total)."%\n";
$meanB = ($sonnetB + $gptB) / 2;
echo "Mean boardroom win rate across judges: ".$pct($meanB, $total)."%\n";

echo "\n=== INTER-JUDGE AGREEMENT ===\n";
$agreed = $agreedB + $agreedS + $tie;
echo "Judges agreed on: {$agreed}/{$total} = ".$pct($agreed, $total)."%\n";
echo "  - both gave it to boardroom: {$agreedB}\n";
echo "  - both gave it to single model: {$agreedS}\n";
echo "  - both called it a tie: {$tie}\n";
echo "Judges disagreed on: {$disagree}\n";

echo "\n=== BY CATEGORY ===\n";
foreach ($byCat as $c => $d) {
    $mean = ($d['sB'] + $d['gB']) / 2;
    echo sprintf(
        "%-15s n=%d  sonnet B=%d  gpt-4o B=%d  mean B%%=%.1f%%  both-agree-B=%d\n",
        $c, $d['n'], $d['sB'], $d['gB'], $mean / $d['n'] * 100, $d['agreeB']
    );
}

echo "\n=== COST ===\n";
echo "Total boardroom: EUR ".number_format($costB, 4)."\n";
echo "Total single:    EUR ".number_format($costS, 4)."\n";
echo "Total run:       EUR ".number_format($costB + $costS, 4)."\n";
echo "Boardroom avg/q: EUR ".number_format($costB / $total, 4)."\n";
echo "Single avg/q:    EUR ".number_format($costS / $total, 4)."\n";
echo "Boardroom cost multiplier vs single: ".round($costB / max(0.0001, $costS), 1)."x\n";

echo "\n=== TIME ===\n";
echo "Total runtime: ".round($time / 60, 1)." min\n";
echo "Avg/q: ".round($time / $total, 1)."s\n";

echo "\n=== UNANIMOUS BOARDROOM WINS (both judges agree) ===\n";
foreach ($rows as $r) {
    if ($r['agree'] && $r['sonnet'] === 'boardroom') {
        echo sprintf("q%d (%s): %s\n", $r['id'], $r['category'], substr($r['question'], 0, 90));
    }
}

echo "\n=== UNANIMOUS SINGLE-MODEL WINS (both judges agree) ===\n";
foreach ($rows as $r) {
    if ($r['agree'] && $r['sonnet'] === 'single model') {
        echo sprintf("q%d (%s): %s\n", $r['id'], $r['category'], substr($r['question'], 0, 90));
    }
}

echo "\n=== DISAGREEMENTS (judges split) ===\n";
foreach ($rows as $r) {
    if (! $r['agree'] && $r['sonnet'] !== null && $r['gpt'] !== null) {
        echo sprintf("q%d (%s): sonnet=%s  gpt-4o=%s\n", $r['id'], $r['category'], $r['sonnet'], $r['gpt']);
    }
}

// Save consolidated analysis for downstream use (report writer, etc.)
file_put_contents(
    $base.'/analysis.json',
    json_encode([
        'totals' => [
            'n' => $total,
            'sonnet_boardroom_wins' => $sonnetB,
            'gpt_4o_boardroom_wins' => $gptB,
            'agreed' => $agreed,
            'agreed_boardroom' => $agreedB,
            'agreed_single' => $agreedS,
            'disagreed' => $disagree,
            'cost_boardroom_eur' => round($costB, 4),
            'cost_single_eur' => round($costS, 4),
            'runtime_minutes' => round($time / 60, 1),
        ],
        'by_category' => $byCat,
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\nAnalysis saved to benchmark/analysis.json\n";
