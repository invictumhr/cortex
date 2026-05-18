<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $table = 'personas';

    protected $fillable = [
        'name',
        'slug',
        'title',
        'avatar_color',
        'avatar_emoji',
        'description',
        'system_prompt',
        'ai_model_id',
        'personality_traits',
        'expertise_areas',
        'communication_style',
        'is_scribe',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'personality_traits' => 'array',
            'expertise_areas' => 'array',
            'is_scribe' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_personas')
            ->using(ChatPersona::class)
            ->withPivot(['is_active', 'joined_at', 'left_at', 'message_count', 'cost'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeScribes(Builder $query): Builder
    {
        return $query->where('is_scribe', true);
    }

    public function scopeSpeakers(Builder $query): Builder
    {
        return $query->where('is_scribe', false);
    }
}
