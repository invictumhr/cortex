<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PowerShellLog extends Model
{
    protected $table = 'powershell_logs';

    protected $fillable = [
        'chat_id',
        'persona_id',
        'command',
        'output',
        'exit_code',
        'execution_time_ms',
        'was_approved',
        'was_blocked',
        'block_reason',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'execution_time_ms' => 'integer',
            'was_approved' => 'boolean',
            'was_blocked' => 'boolean',
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
}
