<?php

namespace App\Services\Chat;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Persona;

/**
 * Rough pre-flight cost estimate for a boardroom run. The estimate is built
 * from this chat's actual configuration — the panel's models, the round count
 * and the measured size of the pinned context/constraints block — rather than
 * from unrelated history, so a context-heavy run is priced higher than a bare
 * one. Honest, not precise: treat the result as an order-of-magnitude figure.
 */
class CostEstimator
{
    /** Persona prompt baseline (system prompt + framing + topic), in tokens. */
    private const BASE_PERSONA_INPUT = 1100;

    /** Recent-transcript block added to a persona prompt from round 2 on. */
    private const TRANSCRIPT_INPUT = 3000;

    /** Typical output sizes, in tokens. */
    private const PERSONA_OUTPUT = 700;

    private const SCRIBE_OUTPUT = 2200;

    private const CHAIR_OUTPUT = 350;

    /** The Chair reads the scribe synthesis plus its own prompt. */
    private const CHAIR_BASE_INPUT = 1600;

    public function __construct(private PersonaResponder $responder) {}

    /**
     * Estimate the EUR cost of one full turn for the given chat.
     *
     * @return array{expected: float, low: float, high: float, speakers: int, rounds: int}
     */
    public function estimate(Chat $chat): array
    {
        $speakers = $chat->activePersonas()
            ->where('is_scribe', false)
            ->where('is_chair', false)
            ->with('aiModel.provider')
            ->get();

        $rounds = max(1, (int) $chat->rounds_per_turn);

        // The pinned context/constraints block is the biggest input-cost
        // variable, so measure it from this chat rather than guessing.
        $contextTokens = $this->roughTokens((string) $chat->context)
            + $this->roughTokens((string) $chat->constraints);

        $models = $speakers
            ->map(fn (Persona $persona) => $this->responder->modelFor($persona, $chat))
            ->filter()
            ->values();

        // Round 1 is independent (context only); later rounds add a transcript.
        $firstInput = self::BASE_PERSONA_INPUT + $contextTokens;
        $laterInput = $firstInput + self::TRANSCRIPT_INPUT;

        $panelCost = fn (int $input): float => (float) $models->sum(
            fn (AiModel $model) => $model->calculateCost($input, self::PERSONA_OUTPUT),
        );

        $expected = $panelCost($firstInput) + max(0, $rounds - 1) * $panelCost($laterInput);

        // The scribe and Chair only run when not in `--fast` mode.
        if ((int) $chat->scribe_interval < 1_000_000) {
            $expected += $this->scribeCost($rounds, $speakers->count(), $contextTokens);
            $expected += $this->chairCost($contextTokens);
        }

        return [
            'expected' => round($expected, 6),
            'low' => round($expected * 0.5, 6),
            'high' => round($expected * 1.9, 6),
            'speakers' => $speakers->count(),
            'rounds' => $rounds,
        ];
    }

    /**
     * Cost of interim scribe summaries plus the guaranteed final synthesis.
     */
    private function scribeCost(int $rounds, int $speakers, int $contextTokens): float
    {
        $model = $this->roleModel('is_scribe');
        if (! $model) {
            return 0.0;
        }

        $roundInterval = max(1, (int) config('cortex.scribe_round_interval', 2));
        $runs = 1; // the guaranteed cumulative final synthesis
        for ($round = 1; $round < $rounds; $round++) {
            if ($round % $roundInterval === 0) {
                $runs++;
            }
        }

        // The cumulative synthesis reads the whole transcript.
        $input = $contextTokens + $rounds * max(1, $speakers) * self::PERSONA_OUTPUT;

        return $runs * $model->calculateCost($input, self::SCRIBE_OUTPUT);
    }

    private function chairCost(int $contextTokens): float
    {
        $model = $this->roleModel('is_chair');

        return $model
            ? $model->calculateCost(self::CHAIR_BASE_INPUT + $contextTokens, self::CHAIR_OUTPUT)
            : 0.0;
    }

    private function roleModel(string $flag): ?AiModel
    {
        return Persona::query()
            ->where($flag, true)
            ->where('is_active', true)
            ->with('aiModel')
            ->first()?->aiModel;
    }

    /** Rough token count using a ~4-characters-per-token heuristic. */
    private function roughTokens(string $text): int
    {
        return (int) ceil(mb_strlen(trim($text)) / 4);
    }
}
