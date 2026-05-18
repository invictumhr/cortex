<?php

namespace App\Services\Ai\Adapters;

class DeepSeekAdapter extends OpenAiCompatibleAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com';
    }
}
