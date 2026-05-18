<?php

namespace App\Services\Ai\Data;

class AiMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param  AiImage[]  $images
     */
    public function __construct(
        public string $role,
        public string $content,
        public array $images = [],
    ) {}

    /**
     * @param  AiImage[]  $images
     */
    public static function user(string $content, array $images = []): self
    {
        return new self(self::ROLE_USER, $content, $images);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }
}
