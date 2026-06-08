<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $table = 'ai_providers';

    public const BILLING_DASHBOARDS = [
        'anthropic' => 'https://console.anthropic.com/settings/billing',
        'openai'    => 'https://platform.openai.com/usage',
        'xai'       => 'https://console.x.ai',
        'google'    => 'https://console.cloud.google.com/billing',
        'mistral'   => 'https://console.mistral.ai/billing',
        'deepseek'  => 'https://platform.deepseek.com/usage',
    ];

    public function getBillingDashboardUrlAttribute(): ?string
    {
        return self::BILLING_DASHBOARDS[$this->slug] ?? null;
    }

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
