<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'ai_provider_id',
        'name',
        'model_string',
        'supports_vision',
        'supports_tools',
        'max_context_tokens',
        'input_cost_per_1m_tokens',
        'output_cost_per_1m_tokens',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'supports_vision' => 'boolean',
            'supports_tools' => 'boolean',
            'max_context_tokens' => 'integer',
            'input_cost_per_1m_tokens' => 'decimal:4',
            'output_cost_per_1m_tokens' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Cost in EUR for a given token usage, based on per-1M pricing.
     */
    public function calculateCost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens / 1_000_000) * (float) $this->input_cost_per_1m_tokens
            + ($outputTokens / 1_000_000) * (float) $this->output_cost_per_1m_tokens;
    }
}
