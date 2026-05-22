<?php

namespace App\Observers;

use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Support\Facades\Log;

/**
 * Watches for the user's *first* qualifying DEPOSIT and tops them up with the
 * first-deposit bonus. Opcija B from the design: €0.50 on signup + €1 after
 * a real first €5+ top-up.
 */
class WalletTransactionObserver
{
    public function __construct(private WalletService $walletService) {}

    public function created(WalletTransaction $tx): void
    {
        if ($tx->type !== WalletTransaction::TYPE_DEPOSIT) {
            return;
        }

        $threshold = (float) config('cortex.billing.first_deposit_threshold', 5.0);
        $bonus = (float) config('cortex.billing.free_credit_first_deposit', 1.0);

        if ($bonus <= 0 || (float) $tx->amount < $threshold) {
            return;
        }

        $wallet = $tx->wallet;
        if (! $wallet) {
            return;
        }

        // Count prior DEPOSIT rows on this wallet — including the row currently
        // being created. We grant only when this is the very first deposit.
        $depositCount = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->count();

        if ($depositCount !== 1) {
            return;
        }

        // And only one bonus ever per wallet, even if a refund cycle ever
        // makes the "first deposit" trigger fire again.
        $alreadyBonused = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_GRANT)
            ->whereJsonContains('metadata->reason', 'first_deposit_bonus')
            ->exists();

        if ($alreadyBonused) {
            return;
        }

        try {
            $this->walletService->grant(
                $wallet,
                $bonus,
                'first_deposit_bonus',
                sourceType: 'wallet_transaction',
                sourceId: $tx->id,
            );
        } catch (\Throwable $e) {
            // Don't break the deposit on bonus failure — log and move on.
            Log::error('[first-deposit-bonus] grant failed', [
                'wallet_id' => $wallet->id,
                'deposit_tx_id' => $tx->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
