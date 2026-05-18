<?php

namespace App\Services\Ai\Adapters;

class XAiAdapter extends OpenAiCompatibleAdapter
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai';
    }
}
