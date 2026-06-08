<?php

namespace App\Services\Chat;

use App\Models\AiModel;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use Illuminate\Support\Str;

/**
 * Generate a short, catchy chat title from the user's topic using the cheapest
 * available model (router_model). Platform cost — not billed to the user.
 *
 * Used by web ChatController, API DiscussController, and CLI CortexDiscuss.
 */
class TitleGenerator
{
    public function __construct(
        private AiProviderFactory $factory,
    ) {}

    /**
     * Generate a title from the topic. Returns null on any failure so callers
     * can fall back to a default string.
     *
     * @param  string       $topic   The discussion topic or description
     * @param  string|null  $prefix  Optional prefix ("API", "CLI") prepended as "API: Title"
     */
    public function generate(string $topic, ?string $prefix = null): ?string
    {
        if (trim($topic) === '') {
            return null;
        }

        try {
            $model = AiModel::where('model_string', config('cortex.router_model'))
                ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
                ->with('provider')
                ->first();

            if (! $model) {
                return null;
            }

            $response = $this->factory->for($model)->sendMessage(
                'Generate a short, catchy title (max 50 characters) for a boardroom discussion. '
                .'Return ONLY the title text, nothing else. No quotes, no prefix, no explanation. '
                .'Write in the same language as the topic.',
                [AiMessage::user($topic)],
                ['max_tokens' => 40, 'temperature' => 0.5],
            );

            $title = trim($response->content, " \t\n\r\0\x0B\"'");

            if ($title === '') {
                return null;
            }

            $title = Str::limit($title, 200, '');

            return $prefix ? "{$prefix}: {$title}" : $title;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
