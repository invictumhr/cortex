<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerShellPermission extends Model
{
    protected $table = 'powershell_permissions';

    protected $fillable = [
        'is_enabled',
        'allowed_commands',
        'blocked_paths',
        'blocked_commands',
        'max_execution_time',
        'require_approval',
        'log_all_commands',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'allowed_commands' => 'array',
            'blocked_paths' => 'array',
            'blocked_commands' => 'array',
            'max_execution_time' => 'integer',
            'require_approval' => 'boolean',
            'log_all_commands' => 'boolean',
        ];
    }

    /**
     * The PowerShell permission set is a singleton row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'is_enabled' => false,
            'allowed_commands' => [],
            'blocked_paths' => [],
            'blocked_commands' => [],
            'max_execution_time' => 30,
            'require_approval' => true,
            'log_all_commands' => true,
        ]);
    }
}
