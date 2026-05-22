<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiTokenUsage extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_INSUFFICIENT_FUNDS = 'insufficient_funds';
    public const STATUS_ERROR = 'error';

    public $timestamps = false;

    protected $fillable = [
        'api_token_id', 'chat_id', 'chat_message_id', 'endpoint',
        'provider_cost', 'user_cost', 'response_time_ms', 'status', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost' => 'decimal:6',
            'user_cost' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
