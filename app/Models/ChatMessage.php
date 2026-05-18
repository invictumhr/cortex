<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    public const ROLE_USER = 'user';
    public const ROLE_PERSONA = 'persona';
    public const ROLE_SCRIBE = 'scribe';
    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'chat_id',
        'persona_id',
        'user_id',
        'role',
        'content',
        'round_number',
        'turn_number',
        'input_tokens',
        'output_tokens',
        'cost',
        'model_used',
        'provider_used',
        'response_time_ms',
        'has_attachments',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'turn_number' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'response_time_ms' => 'integer',
            'has_attachments' => 'boolean',
            'metadata' => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class);
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
