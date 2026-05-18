<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Adapters\AnthropicAdapter;
use App\Services\Ai\Adapters\DeepSeekAdapter;
use App\Services\Ai\Adapters\GoogleAdapter;
use App\Services\Ai\Adapters\MistralAdapter;
use App\Services\Ai\Adapters\OpenAiAdapter;
use App\Services\Ai\Adapters\XAiAdapter;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Exceptions\AiProviderException;

class AiProviderFactory
{
    /**
     * Resolve the correct provider adapter for a given AI model.
     */
    public function for(AiModel $model): AiProviderInterface
    {
        $model->loadMissing('provider');
        $slug = $model->provider?->slug;

        return match ($slug) {
            'anthropic' => new AnthropicAdapter($model),
            'openai' => new OpenAiAdapter($model),
            'xai' => new XAiAdapter($model),
            'google' => new GoogleAdapter($model),
            'mistral' => new MistralAdapter($model),
            'deepseek' => new DeepSeekAdapter($model),
            default => throw new AiProviderException('Nepoznat AI provider: '.($slug ?? 'null')),
        };
    }
}
