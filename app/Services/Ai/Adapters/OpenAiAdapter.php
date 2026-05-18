<?php

namespace App\Services\Ai\Adapters;

class OpenAiAdapter extends OpenAiCompatibleAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com';
    }
}
