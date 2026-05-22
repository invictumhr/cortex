<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only ledger row. Each one names what type of mutation it represents
 * (RESERVE/RELEASE/DEBIT/REFUND/DEPOSIT/GRANT/ADJUSTMENT) and points back to
 * the parent transaction when it's a follow-up (e.g. REFUND links to its DEBIT).
 *
 * Never UPDATE these rows in place. Compensating mutations are new rows.
 */
class WalletTransaction extends Model
{
    public const TYPE_DEPOSIT    = 'DEPOSIT';
    public const TYPE_RESERVE    = 'RESERVE';
    public const TYPE_RELEASE    = 'RELEASE';
    public const TYPE_DEBIT      = 'DEBIT';
    public const TYPE_REFUND     = 'REFUND';
    public const TYPE_GRANT      = 'GRANT';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    // We set created_at by hand (useCurrent in the migration), and the table has
    // no updated_at — disable Eloquent's auto-touch to avoid update warnings.
    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'reserved_delta', 'source_type', 'source_id',
        'reserved_id', 'parent_transaction_id', 'payment_source_ref',
        'rate_input_snapshot', 'rate_output_snapshot', 'provider_id',
        'provider_cost', 'user_cost', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'reserved_delta' => 'decimal:6',
            'rate_input_snapshot' => 'decimal:6',
            'rate_output_snapshot' => 'decimal:6',
            'provider_cost' => 'decimal:6',
            'user_cost' => 'decimal:6',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** The RESERVE row this transaction is settling (DEBIT/RELEASE). */
    public function reserve(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reserved_id');
    }

    /** The DEBIT row that a REFUND is reversing. */
    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    /** RELEASE/DEBIT rows produced from this RESERVE. */
    public function settlements(): HasMany
    {
        return $this->hasMany(self::class, 'reserved_id');
    }

    /** REFUND rows produced from this DEBIT. */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id');
    }
}
