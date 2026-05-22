<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientFundsException;
use App\Models\AiModel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Single point of mutation for the wallet ledger.
 *
 * Every public method that touches `wallets.balance` or `wallets.reserved_balance`
 * runs inside a DB transaction with `SELECT ... FOR UPDATE` on the wallet row,
 * so two parallel boardroom starts for the same user can't both see the same
 * euros and both decide they're spendable.
 *
 * # Mental model
 * - `balance`          = spendable (not held, not yet spent)
 * - `reserved_balance` = held for in-flight runs
 * - total funds        = balance + reserved_balance
 * - availableBalance() = balance (UI calls this "spendable")
 *
 * # Ledger semantics
 * Every WalletTransaction row carries:
 *   amount         = signed impact on TOTAL funds (balance + reserved_balance)
 *   reserved_delta = signed impact on reserved_balance only
 *
 * Operation              amount             reserved_delta
 * ---------              ------             --------------
 * GRANT/DEPOSIT/REFUND   +X                 0
 * DEBIT (settling reserve)  -X              -X   (held amount becomes spent)
 * DEBIT (no reserve)     -X                 0    (admin manual)
 * RESERVE                0 (move only)      +X
 * RELEASE                0 (move only)      -X
 * ADJUSTMENT             ±X                 0
 *
 * # Invariants daily reconciliation enforces
 *   balance + reserved_balance == sum(amount)
 *   reserved_balance           == sum(reserved_delta)
 */
class WalletService
{
    /**
     * Lazily create the wallet on first access. New users get a zero-balance
     * wallet; the signup free credit is granted separately so the grant can
     * be conditional on email verification, IP fingerprinting, etc.
     */
    public function forUser(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id], [
            'balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * Pre-flight hold: move `estimatedUserCost` from `balance` into
     * `reserved_balance`. Throws InsufficientFundsException if balance is short.
     *
     * @param  array<string,mixed>  $metadata  Free-form context for the ledger row.
     */
    public function reserve(
        Wallet $wallet,
        float $estimatedUserCost,
        AiModel $rateSnapshotModel,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($estimatedUserCost <= 0) {
            throw new InvalidArgumentException('Reserve amount must be positive.');
        }

        return DB::transaction(function () use ($wallet, $estimatedUserCost, $rateSnapshotModel, $sourceType, $sourceId, $metadata) {
            // Re-fetch under FOR UPDATE — any other transaction touching this
            // row queues behind us. This is the concurrency keystone.
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new RuntimeException("Wallet #{$wallet->id} disappeared mid-transaction.");
            }

            if ((float) $locked->balance + 1e-9 < $estimatedUserCost) {
                throw new InsufficientFundsException(
                    $locked->id,
                    (float) $locked->balance,
                    $estimatedUserCost,
                );
            }

            $locked->balance = (float) $locked->balance - $estimatedUserCost;
            $locked->reserved_balance = (float) $locked->reserved_balance + $estimatedUserCost;
            $locked->save();

            return WalletTransaction::create([
                'wallet_id' => $locked->id,
                'type' => WalletTransaction::TYPE_RESERVE,
                'amount' => 0,                          // total funds unchanged
                'reserved_delta' => $estimatedUserCost, // moved from balance to reserved
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                // AiModel stores rates per 1M tokens; we keep that unit in the
                // ledger so downstream cost math doesn't shift scale.
                'rate_input_snapshot' => $rateSnapshotModel->input_cost_per_1m_tokens,
                'rate_output_snapshot' => $rateSnapshotModel->output_cost_per_1m_tokens,
                'provider_id' => $rateSnapshotModel->ai_provider_id,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Settle a RESERVE: write a DEBIT for the actual user cost and, if the
     * actual cost was less than the reserve, a RELEASE row for the remainder
     * so spendable balance is restored.
     *
     * Both rows are written in the same DB transaction so the wallet never
     * shows a half-settled state.
     *
     * @return array{debit: WalletTransaction, leftover: ?WalletTransaction}
     */
    public function commitDebit(
        WalletTransaction $reserve,
        float $providerCost,
        float $actualUserCost,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): array {
        if ($reserve->type !== WalletTransaction::TYPE_RESERVE) {
            throw new InvalidArgumentException("Cannot commit non-RESERVE transaction #{$reserve->id}.");
        }
        if ($providerCost < 0 || $actualUserCost < 0) {
            throw new InvalidArgumentException('Costs must be non-negative.');
        }

        $reservedAmount = (float) $reserve->reserved_delta;
        $poisonRatio = (float) config('cortex.billing.poison_pill_ratio', 1.3);

        if ($reservedAmount > 0 && $actualUserCost > $reservedAmount * $poisonRatio) {
            // Drift past the safety multiplier — record it for ops review but
            // don't fail the call. Margin-drift exposure is on us, not the user.
            Log::warning('[wallet] poison pill triggered', [
                'wallet_id' => $reserve->wallet_id,
                'reserve_id' => $reserve->id,
                'reserved' => $reservedAmount,
                'actual' => $actualUserCost,
                'ratio' => round($actualUserCost / $reservedAmount, 3),
            ]);
        }

        return DB::transaction(function () use ($reserve, $providerCost, $actualUserCost, $reservedAmount, $sourceType, $sourceId, $metadata) {
            $wallet = Wallet::whereKey($reserve->wallet_id)->lockForUpdate()->first();

            if (! $wallet) {
                throw new RuntimeException("Wallet #{$reserve->wallet_id} disappeared mid-transaction.");
            }

            // DEBIT consumes `actualUserCost` from reserved (the held funds
            // become spent and leave the wallet).
            $wallet->reserved_balance = max(0, (float) $wallet->reserved_balance - $actualUserCost);
            $wallet->save();

            $debit = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => WalletTransaction::TYPE_DEBIT,
                'amount' => -$actualUserCost,         // total funds drop
                'reserved_delta' => -$actualUserCost, // held amount became spent
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reserved_id' => $reserve->id,
                'rate_input_snapshot' => $reserve->rate_input_snapshot,
                'rate_output_snapshot' => $reserve->rate_output_snapshot,
                'provider_id' => $reserve->provider_id,
                'provider_cost' => $providerCost,
                'user_cost' => $actualUserCost,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            // RELEASE the unused remainder of the reserve back to spendable.
            $leftover = null;
            $remainder = $reservedAmount - $actualUserCost;
            if ($remainder > 1e-9) {
                $wallet->balance = (float) $wallet->balance + $remainder;
                $wallet->reserved_balance = max(0, (float) $wallet->reserved_balance - $remainder);
                $wallet->save();

                $leftover = WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => WalletTransaction::TYPE_RELEASE,
                    'amount' => 0,
                    'reserved_delta' => -$remainder,
                    'reserved_id' => $reserve->id,
                    'metadata' => ['reason' => 'commit_leftover'],
                    'created_at' => now(),
                ]);
            }

            return ['debit' => $debit, 'leftover' => $leftover];
        });
    }

    /**
     * Roll back a RESERVE without any debit — used when the persona call
     * errored before producing billable output. The full reserved amount
     * moves back to spendable.
     */
    public function release(WalletTransaction $reserve, string $reason): WalletTransaction
    {
        if ($reserve->type !== WalletTransaction::TYPE_RESERVE) {
            throw new InvalidArgumentException("Cannot release non-RESERVE transaction #{$reserve->id}.");
        }

        return DB::transaction(function () use ($reserve, $reason) {
            $wallet = Wallet::whereKey($reserve->wallet_id)->lockForUpdate()->first();

            if (! $wallet) {
                throw new RuntimeException("Wallet #{$reserve->wallet_id} disappeared mid-transaction.");
            }

            $amount = (float) $reserve->reserved_delta;
            $wallet->balance = (float) $wallet->balance + $amount;
            $wallet->reserved_balance = max(0, (float) $wallet->reserved_balance - $amount);
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => WalletTransaction::TYPE_RELEASE,
                'amount' => 0,
                'reserved_delta' => -$amount,
                'reserved_id' => $reserve->id,
                'metadata' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Top-up from an external payment processor. The `paymentSourceRef`
     * (e.g. Paddle's transaction id) is indexed and used for idempotency —
     * webhooks redeliver, and we don't want to apply the same payment twice.
     */
    public function deposit(
        Wallet $wallet,
        float $amount,
        string $paymentSourceRef,
        array $metadata = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Deposit must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $paymentSourceRef, $metadata) {
            // Idempotency check — if a row with this payment ref already
            // exists, return it instead of double-crediting.
            $existing = WalletTransaction::where('payment_source_ref', $paymentSourceRef)
                ->where('type', WalletTransaction::TYPE_DEPOSIT)
                ->first();
            if ($existing) {
                return $existing;
            }

            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException("Wallet #{$wallet->id} disappeared mid-transaction.");
            }

            $locked->balance = (float) $locked->balance + $amount;
            $locked->save();

            return WalletTransaction::create([
                'wallet_id' => $locked->id,
                'type' => WalletTransaction::TYPE_DEPOSIT,
                'amount' => $amount,
                'reserved_delta' => 0,
                'payment_source_ref' => $paymentSourceRef,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Admin or signup-flow free credit. Reason is required so support can
     * later answer "why did this user get €1?".
     */
    public function grant(
        Wallet $wallet,
        float $amount,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Grant must be positive.');
        }

        return DB::transaction(function () use ($wallet, $amount, $reason, $sourceType, $sourceId) {
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException("Wallet #{$wallet->id} disappeared mid-transaction.");
            }

            $locked->balance = (float) $locked->balance + $amount;
            $locked->save();

            return WalletTransaction::create([
                'wallet_id' => $locked->id,
                'type' => WalletTransaction::TYPE_GRANT,
                'amount' => $amount,
                'reserved_delta' => 0,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => ['reason' => $reason],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Pick the per-debit margin multiplier. Enterprise tier kicks in when a
     * user has spent above the threshold in the trailing 30 days, computed
     * from the wallet's own ledger.
     */
    public function marginMultiplierFor(User $user): float
    {
        $wallet = $user->wallet;
        if (! $wallet) {
            return (float) config('cortex.billing.margin_hobby', 2.0);
        }

        $threshold = (float) config('cortex.billing.enterprise_threshold_eur', 100.0);

        $spend30d = (float) WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('user_cost');

        return $spend30d >= $threshold
            ? (float) config('cortex.billing.margin_enterprise', 1.7)
            : (float) config('cortex.billing.margin_hobby', 2.0);
    }
}
