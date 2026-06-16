<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One wallet per user. Mutate ONLY through WalletService so concurrency,
 * snapshots, and the ledger invariant stay enforced.
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'balance', 'reserved_balance', 'currency', 'maintenance_flag',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:6',
            'reserved_balance' => 'decimal:6',
            'maintenance_flag' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Spendable euros. `WalletService::reserve()` already moves held funds out
     * of `balance` and into `reserved_balance`, so `balance` alone IS the
     * spendable amount — subtracting `reserved_balance` again would count every
     * in-flight hold twice. This is what the UI and pre-flight checks read.
     */
    public function availableBalance(): float
    {
        return (float) $this->balance;
    }
}
