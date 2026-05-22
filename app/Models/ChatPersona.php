<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ChatPersona extends Pivot
{
    protected $table = 'chat_personas';

    public $incrementing = true;

    protected $fillable = [
        'chat_id',
        'persona_id',
        'ai_model_id',
        'is_active',
        'joined_at',
        'left_at',
        'message_count',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'message_count' => 'integer',
            'cost' => 'decimal:6',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /** Per-chat AI model override — when null, persona's default model is used. */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }
}
