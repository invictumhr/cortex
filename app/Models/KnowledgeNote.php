<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeNote extends Model
{
    protected $table = 'knowledge_notes';

    protected $fillable = [
        'chat_id',
        'content',
        'tags',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
