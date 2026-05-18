<?php

namespace App\Services\Ai\Data;

class AiImage
{
    public function __construct(
        public string $mimeType,
        public string $base64Data,
    ) {}

    public function dataUri(): string
    {
        return "data:{$this->mimeType};base64,{$this->base64Data}";
    }
}
