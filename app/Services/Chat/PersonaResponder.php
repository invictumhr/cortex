<?php

namespace App\Services\Chat;

use App\Events\ChatCostUpdated;
use App\Events\ChatMessageCreated;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatPersona;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use RuntimeException;

class PersonaResponder
{
    public function __construct(
        private AiProviderFactory $factory,
        private ContextBuilder $contextBuilder,
    ) {}

    /**
     * Generate one persona contribution, persist it, and broadcast it.
     */
    public function respond(Chat $chat, Persona $persona, int $round, int $turn): ChatMessage
    {
        $model = $persona->aiModel;

        if (! $model) {
            throw new RuntimeException("Persona {$persona->name} nema dodijeljen AI model.");
        }

        $adapter = $this->factory->for($model);
        [$system, $messages, $options] = $this->contextBuilder->build($chat, $persona);

        $response = $adapter->sendMessage($system, $messages, $options);
        $cost = $adapter->calculateCost($response->inputTokens, $response->outputTokens);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $persona->id,
            'role' => ChatMessage::ROLE_PERSONA,
            'content' => $response->content !== '' ? $response->content : '(persona nije vratila tekst)',
            'round_number' => $round,
            'turn_number' => $turn,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
            'model_used' => $model->model_string,
            'provider_used' => $model->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => ['finish_reason' => $response->finishReason],
        ]);

        $this->recordUsage($chat, $persona, $response->inputTokens, $response->outputTokens, $cost);

        broadcast(new ChatMessageCreated($message));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $message;
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
