<?php

namespace App\Console\Commands;

use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for crashed runs. If a queue worker dies (fatal, kill, timeout)
 * after WalletService::reserve() but before commitDebit()/release(), the
 * RESERVE row never gets a settlement child and the money stays frozen in
 * `reserved_balance` forever. Crucially, the daily reconciliation does NOT
 * catch this — both ledger invariants still hold for an orphaned reserve.
 *
 * This sweep finds RESERVE rows older than --hours with no DEBIT/RELEASE
 * children and releases them back to spendable. The age threshold is far
 * above any legitimate run duration, so an in-flight discussion can't be
 * swept out from under itself. Scheduled hourly via routes/console.php.
 */
class WalletReleaseStaleReserves extends Command
{
    protected $signature = 'cortex:wallet-release-stale
        {--hours=6 : Minimum age in hours before a reserve counts as orphaned}
        {--dry-run : List orphaned reserves without releasing them}';

    protected $description = 'Release RESERVE ledger rows that were never settled (crashed runs).';

    public function handle(WalletService $wallets): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $stale = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_RESERVE)
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('settlements')
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("OK — no orphaned reserves older than {$hours}h.");

            return self::SUCCESS;
        }

        $released = [];
        $failed = 0;

        foreach ($stale as $reserve) {
            $row = [
                'reserve_id' => $reserve->id,
                'wallet_id' => $reserve->wallet_id,
                'amount' => (float) $reserve->reserved_delta,
                'age_hours' => (int) $reserve->created_at->diffInHours(now()),
            ];

            if ($dryRun) {
                $released[] = $row;

                continue;
            }

            try {
                $wallets->release($reserve, "stale_reserve_sweep (>{$hours}h)");
                $released[] = $row;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("Failed to release reserve #{$reserve->id}: {$e->getMessage()}");
            }
        }

        $verb = $dryRun ? 'WOULD release' : 'Released';
        $this->warn("{$verb} ".count($released)." orphaned reserve(s) older than {$hours}h:");
        $this->table(
            ['reserve', 'wallet', 'amount €', 'age (h)'],
            array_map(fn ($r) => [
                $r['reserve_id'], $r['wallet_id'],
                sprintf('%.6f', $r['amount']), $r['age_hours'],
            ], $released),
        );

        if (! $dryRun && $released !== []) {
            // Orphaned reserves mean jobs are dying between reserve and settle —
            // worth an ops look even though the money is now back.
            $context = ['count' => count($released), 'rows' => $released];
            Log::warning('[wallet-release-stale] released orphaned reserves', $context);
            Log::channel('alerts')->warning('[wallet-release-stale] released orphaned reserves', $context);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
