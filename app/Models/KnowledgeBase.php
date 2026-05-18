<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeBase extends Model
{
    protected $table = 'knowledge_base';

    protected $fillable = [
        'digest',
        'notes_count',
        'digested_at',
    ];

    protected function casts(): array
    {
        return [
            'notes_count' => 'integer',
            'digested_at' => 'datetime',
        ];
    }

    /**
     * The knowledge base is a single growing row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'digest' => null,
            'notes_count' => 0,
        ]);
    }
}
