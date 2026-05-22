<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch / code-generation job: N codes minted with the same face value.
 *
 * The codes themselves live on TopupCode and link back via batch_id. We
 * preserve the batch row even after all its codes are redeemed so that admin
 * always has a "we issued these on this day for this purpose" audit trail.
 */
class TopupCodeBatch extends Model
{
    protected $fillable = [
        'label', 'amount_per_code', 'code_count', 'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_per_code' => 'decimal:6',
            'code_count' => 'integer',
        ];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(TopupCode::class, 'batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Total face value of all codes in this batch — code_count × amount_per_code.
     * Used for the admin list view ("how much did this batch put on the street").
     */
    public function totalValue(): float
    {
        return (float) $this->amount_per_code * (int) $this->code_count;
    }
}
