<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Model-catalog health check. Turns model end-of-life from a thing someone has
 * to remember into a scheduled alert. Two checks:
 *
 *  1. RETIREMENT — active models whose `retires_at` is past due (critical) or
 *     within --days (warning). Past-due-but-still-active means the catalog is
 *     about to start 404-ing under production traffic.
 *
 *  2. CONFIG WIRING — every model string referenced from config/cortex.php
 *     (fallback, router, flagship/tier/architect/benchmark lists) must resolve
 *     to an active, non-past-due model. This is exactly the class of bug that
 *     had fallback_model/router_model pointing at gpt-4o-mini on its exit track.
 *
 * Scheduled daily via routes/console.php; exits FAILURE on any critical finding
 * so the scheduler surfaces it. Alerts also go to the `alerts` log channel.
 */
class ModelHealth extends Command
{
    protected $signature = 'cortex:model-health
        {--days=30 : Warn about active models retiring within this many days}
        {--json : Emit a JSON report instead of tables}';

    protected $description = 'Flag AI models that are retiring (or already past due) and config that points at them.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $today = Carbon::today();

        $critical = [];
        $warning = [];

        // ── 1. Retirement dates on active models ────────────────────────
        foreach (AiModel::retiringWithin($days)->orderBy('retires_at')->get() as $model) {
            $retires = Carbon::parse($model->retires_at);
            $row = [
                'kind' => 'retirement',
                'model' => $model->model_string,
                'retires_at' => $retires->toDateString(),
                'days_left' => (int) round($today->diffInDays($retires, false)),
            ];

            if ($retires->lte($today)) {
                $critical[] = $row + ['note' => 'active but past retirement date — will 404'];
            } else {
                $warning[] = $row + ['note' => 'retiring soon'];
            }
        }

        // ── 2. Config-referenced models must be active & not past due ────
        $byString = AiModel::query()->get()->keyBy('model_string');

        foreach ($this->configuredModelStrings() as $where => $modelString) {
            $model = $byString->get($modelString);

            if (! $model) {
                $critical[] = ['kind' => 'config', 'model' => $modelString, 'where' => $where, 'note' => 'referenced in config but not in catalog'];

                continue;
            }
            if (! $model->is_active) {
                $critical[] = ['kind' => 'config', 'model' => $modelString, 'where' => $where, 'note' => 'referenced in config but INACTIVE'];

                continue;
            }
            if ($model->retires_at && Carbon::parse($model->retires_at)->lte($today)) {
                $critical[] = ['kind' => 'config', 'model' => $modelString, 'where' => $where, 'note' => 'config points at a past-due model'];
            } elseif ($model->retires_at && Carbon::parse($model->retires_at)->lte($today->copy()->addDays($days))) {
                $warning[] = ['kind' => 'config', 'model' => $modelString, 'where' => $where, 'note' => 'config points at a model retiring soon', 'retires_at' => Carbon::parse($model->retires_at)->toDateString()];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked_at' => now()->toIso8601String(),
                'window_days' => $days,
                'critical' => array_values($critical),
                'warning' => array_values($warning),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $critical === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($critical === [] && $warning === []) {
            $this->info("OK — no active model retires within {$days} days and all config references are healthy.");

            return self::SUCCESS;
        }

        if ($warning !== []) {
            $this->warn('Models retiring soon / config nearing end-of-life:');
            $this->renderTable($warning);
            Log::channel('alerts')->warning('[model-health] models retiring soon', ['rows' => $warning]);
        }

        if ($critical !== []) {
            $this->error('CRITICAL — past-due or broken model references:');
            $this->renderTable($critical);
            Log::channel('alerts')->critical('[model-health] past-due or broken model references', ['rows' => $critical]);
        }

        return $critical === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every model string the orchestration config depends on, keyed by a
     * human-readable location so the operator knows what to repoint.
     *
     * @return array<string, string>
     */
    private function configuredModelStrings(): array
    {
        $refs = [];
        $add = function (string $where, mixed $value) use (&$refs): void {
            if (is_string($value) && $value !== '') {
                $refs[$where] = $value;
            }
        };

        $add('fallback_model', config('cortex.fallback_model'));
        $add('router_model', config('cortex.router_model'));
        $add('architect_model', config('cortex.architect_model'));
        $add('benchmark_control_model', config('cortex.benchmark_control_model'));
        $add('benchmark_evaluator_model', config('cortex.benchmark_evaluator_model'));

        foreach ((array) config('cortex.flagship_models', []) as $provider => $m) {
            $add("flagship_models.{$provider}", $m);
        }
        foreach ((array) config('cortex.architect_panel_models', []) as $i => $m) {
            $add("architect_panel_models[{$i}]", $m);
        }
        foreach ((array) config('cortex.model_tiers', []) as $tier => $models) {
            foreach ((array) $models as $i => $m) {
                $add("model_tiers.{$tier}[{$i}]", $m);
            }
        }

        return $refs;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function renderTable(array $rows): void
    {
        $this->table(
            ['kind', 'model', 'where / retires', 'note'],
            array_map(fn (array $r) => [
                $r['kind'] ?? '',
                $r['model'] ?? '',
                $r['where'] ?? ($r['retires_at'] ?? ''),
                $r['note'] ?? '',
            ], $rows),
        );
    }
}
