<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily wallet integrity check. For every wallet we recompute both invariants
 * from the ledger:
 *
 *   sum(amount)         must equal  balance + reserved_balance
 *   sum(reserved_delta) must equal  reserved_balance
 *
 * Anything more than 1 cent off lands in `--alert` output and the log so ops
 * can investigate. Scheduled daily via routes/console.php.
 */
class WalletReconcile extends Command
{
    protected $signature = 'cortex:wallet-reconcile {--tolerance=0.01 : Acceptable drift in EUR before alerting}';

    protected $description = 'Verify every wallet matches its ledger; report drift.';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');

        $broken = [];
        $checked = 0;

        Wallet::query()->orderBy('id')->chunkById(200, function ($wallets) use ($tolerance, &$broken, &$checked) {
            foreach ($wallets as $wallet) {
                $checked++;

                $totals = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->selectRaw('COALESCE(SUM(amount),0) AS sum_amount, COALESCE(SUM(reserved_delta),0) AS sum_reserved')
                    ->first();

                $sumAmount = (float) $totals->sum_amount;
                $sumReserved = (float) $totals->sum_reserved;
                $balance = (float) $wallet->balance;
                $reserved = (float) $wallet->reserved_balance;
                $total = $balance + $reserved;

                $amountDrift = round($sumAmount - $total, 6);
                $reservedDrift = round($sumReserved - $reserved, 6);

                if (abs($amountDrift) > $tolerance || abs($reservedDrift) > $tolerance) {
                    $broken[] = [
                        'wallet_id' => $wallet->id,
                        'user_id' => $wallet->user_id,
                        'amount_drift' => $amountDrift,
                        'reserved_drift' => $reservedDrift,
                        'balance' => $balance,
                        'reserved' => $reserved,
                    ];
                }
            }
        });

        if ($broken === []) {
            $this->info("OK — checked $checked wallet(s), no drift above €$tolerance.");

            return self::SUCCESS;
        }

        $this->error('DRIFT — '.count($broken).' wallet(s) out of sync:');
        $this->table(
            ['wallet', 'user', 'amount drift', 'reserved drift', 'balance', 'reserved'],
            array_map(fn ($r) => [
                $r['wallet_id'], $r['user_id'],
                sprintf('%+.6f', $r['amount_drift']),
                sprintf('%+.6f', $r['reserved_drift']),
                sprintf('%.6f', $r['balance']),
                sprintf('%.6f', $r['reserved']),
            ], $broken),
        );
        Log::critical('[wallet-reconcile] drift detected', ['count' => count($broken), 'rows' => $broken]);
        Log::channel('alerts')->critical('[wallet-reconcile] drift detected', ['count' => count($broken), 'rows' => $broken]);

        return self::FAILURE;
    }
}
