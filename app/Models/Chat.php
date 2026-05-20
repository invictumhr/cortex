<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $table = 'chats';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'context',
        'constraints',
        'strong',
        'rounds_per_turn',
        'continuous',
        'language',
        'current_round',
        'total_messages',
        'total_input_tokens',
        'total_output_tokens',
        'total_cost',
        'status',
        'last_scribe_summary_at',
        'scribe_interval',
    ];

    protected function casts(): array
    {
        return [
            'strong' => 'boolean',
            'rounds_per_turn' => 'integer',
            'continuous' => 'boolean',
            'current_round' => 'integer',
            'total_messages' => 'integer',
            'total_input_tokens' => 'integer',
            'total_output_tokens' => 'integer',
            'total_cost' => 'decimal:6',
            'last_scribe_summary_at' => 'datetime',
            'scribe_interval' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personas(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'chat_personas')
            ->using(ChatPersona::class)
            ->withPivot(['is_active', 'joined_at', 'left_at', 'message_count', 'cost'])
            ->withTimestamps();
    }

    public function activePersonas(): BelongsToMany
    {
        return $this->personas()->wherePivot('is_active', true);
    }

    public function chatPersonas(): HasMany
    {
        return $this->hasMany(ChatPersona::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function scribeSummaries(): HasMany
    {
        return $this->hasMany(ScribeSummary::class);
    }

    public function powershellLogs(): HasMany
    {
        return $this->hasMany(PowerShellLog::class);
    }

    public function latestScribeSummary(): ?ScribeSummary
    {
        return $this->scribeSummaries()->latest('id')->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
