<?php

namespace App\Services\Chat;

use App\Events\ChatCostUpdated;
use App\Events\ChatMessageCreated;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatPersona;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use RuntimeException;
use Throwable;

class PersonaResponder
{
    public function __construct(
        private AiProviderFactory $factory,
        private ContextBuilder $contextBuilder,
    ) {}

    /**
     * Generate one persona contribution, persist it, and broadcast it.
     *
     * The persona's own model is tried first; if it errors or returns an empty
     * answer, a fallback model takes over so the boardroom keeps the voice.
     */
    public function respond(Chat $chat, Persona $persona, int $round, int $turn): ChatMessage
    {
        $primary = $this->modelFor($persona, $chat);

        if (! $primary) {
            throw new RuntimeException("Persona {$persona->name} nema dodijeljen AI model.");
        }

        [$system, $messages, $options] = $this->contextBuilder->build($chat, $persona);

        $attempts = [$primary];
        if ($fallback = $this->fallbackModel($primary)) {
            $attempts[] = $fallback;
        }

        $response = null;
        $usedModel = $primary;
        $failure = 'nepoznata greška';

        foreach ($attempts as $model) {
            try {
                $candidate = $this->factory->for($model)->sendMessage($system, $messages, $options);

                if (trim($candidate->content) !== '') {
                    $response = $candidate;
                    $usedModel = $model;
                    break;
                }

                $failure = "model {$model->model_string} vratio prazan odgovor";
            } catch (Throwable $e) {
                $failure = "model {$model->model_string}: ".$e->getMessage();
            }
        }

        if ($response === null) {
            throw new RuntimeException('ni primarni ni fallback model nisu odgovorili — '.$failure);
        }

        $cost = $usedModel->calculateCost($response->inputTokens, $response->outputTokens);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $persona->id,
            'role' => ChatMessage::ROLE_PERSONA,
            'content' => $response->content,
            'round_number' => $round,
            'turn_number' => $turn,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
            'model_used' => $usedModel->model_string,
            'provider_used' => $usedModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => array_filter([
                'finish_reason' => $response->finishReason,
                'fallback_from' => $usedModel->id === $primary->id ? null : $primary->model_string,
            ], static fn ($value) => $value !== null),
        ]);

        $this->recordUsage($chat, $persona, $response->inputTokens, $response->outputTokens, $cost);

        broadcast(new ChatMessageCreated($message));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $message;
    }

    /**
     * The model a persona runs on — its assigned model by default, or its
     * provider's flagship model when the chat was started with --strong.
     * Public so CostEstimator can price a run with the same model selection.
     */
    public function modelFor(Persona $persona, Chat $chat): ?AiModel
    {
        $base = $persona->aiModel;

        if (! $chat->strong || ! $base || ! $base->provider) {
            return $base;
        }

        $flagship = ((array) config('cortex.flagship_models'))[$base->provider->slug] ?? null;

        if (! $flagship || $flagship === $base->model_string) {
            return $base;
        }

        $upgraded = AiModel::query()
            ->where('model_string', $flagship)
            ->where('is_active', true)
            ->with('provider')
            ->first();

        return ($upgraded && filled($upgraded->provider?->api_key)) ? $upgraded : $base;
    }

    /**
     * Resolve the configured fallback model, skipping it when it matches the
     * persona's primary model or its provider has no API key.
     */
    private function fallbackModel(AiModel $primary): ?AiModel
    {
        $modelString = (string) config('cortex.fallback_model', '');

        if ($modelString === '' || $modelString === $primary->model_string) {
            return null;
        }

        $model = AiModel::query()
            ->where('model_string', $modelString)
            ->where('is_active', true)
            ->with('provider')
            ->first();

        if (! $model || blank($model->provider?->api_key)) {
            return null;
        }

        return $model;
    }

    private function recordUsage(Chat $chat, Persona $persona, int $inputTokens, int $outputTokens, float $cost): void
    {
        $chat->increment('total_messages');
        $chat->increment('total_input_tokens', $inputTokens);
        $chat->increment('total_output_tokens', $outputTokens);
        $chat->increment('total_cost', $cost);

        $pivot = ChatPersona::query()
            ->where('chat_id', $chat->id)
            ->where('persona_id', $persona->id)
            ->first();

        if ($pivot) {
            $pivot->increment('message_count');
            $pivot->increment('cost', $cost);
        }
    }
}
