<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScribeSummary extends Model
{
    protected $table = 'scribe_summaries';

    protected $fillable = [
        'chat_id',
        'persona_id',
        'from_message_id',
        'to_message_id',
        'summary',
        'key_decisions',
        'key_ideas',
        'open_questions',
        'action_items',
        'assumptions_to_validate',
        'input_tokens',
        'output_tokens',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'key_decisions' => 'array',
            'key_ideas' => 'array',
            'open_questions' => 'array',
            'action_items' => 'array',
            'assumptions_to_validate' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
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

    public function fromMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'from_message_id');
    }

    public function toMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'to_message_id');
    }
}
