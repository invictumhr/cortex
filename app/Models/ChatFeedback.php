<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatFeedback extends Model
{
    protected $table = 'chat_feedback';

    protected $fillable = [
        'chat_id',
        'rating',
        'comment',
        'helpful_ideas',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'helpful_ideas' => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
