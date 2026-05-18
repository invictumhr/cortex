<?php

namespace App\Services\Ai\Adapters;

class MistralAdapter extends OpenAiCompatibleAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.mistral.ai';
    }
}
