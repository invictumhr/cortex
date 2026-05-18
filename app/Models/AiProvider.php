<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $table = 'ai_providers';

    protected $fillable = [
        'name',
        'slug',
        'api_key',
        'api_base_url',
        'is_active',
        'settings',
    ];

    /**
     * api_key is never exposed via array/JSON serialization.
     */
    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }
}
