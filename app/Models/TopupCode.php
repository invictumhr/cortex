<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopupCode extends Model
{
    protected $fillable = [
        'code_hash', 'amount', 'batch_label', 'redeemed_at',
        'redeemed_by_user_id', 'redeemed_ip_hash', 'wallet_transaction_id',
        'metadata', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'redeemed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }
}
