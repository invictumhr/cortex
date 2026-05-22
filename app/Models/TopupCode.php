<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopupCode extends Model
{
    protected $fillable = [
        'batch_id', 'code_hash', 'encrypted_code', 'amount', 'batch_label',
        'redeemed_at', 'redeemed_by_user_id', 'redeemed_ip_hash',
        'wallet_transaction_id', 'metadata', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'redeemed_at' => 'datetime',
            'metadata' => 'array',
            // Laravel encrypts on save and decrypts on read using APP_KEY. The
            // raw column stores Laravel\Encryption envelope bytes, so a DB-only
            // breach can't reverse them — the export-CSV path is the only
            // legitimate reader.
            'encrypted_code' => 'encrypted',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TopupCodeBatch::class, 'batch_id');
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
