<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAttachment extends Model
{
    protected $table = 'chat_attachments';

    public const TYPE_IMAGE = 'image';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_URL = 'url';
    public const TYPE_POWERSHELL_OUTPUT = 'powershell_output';

    protected $fillable = [
        'chat_message_id',
        'type',
        'file_path',
        'url',
        'extracted_content',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }
}
