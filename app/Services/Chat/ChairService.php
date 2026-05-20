<?php

namespace App\Services\Chat;

use App\Events\ChatCostUpdated;
use App\Events\ChatMessageCreated;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use Throwable;

/**
 * After a discussion ends, the Chair forces one decision out of the scribe's
 * synthesis — turning a menu of ideas into a single recommended path.
 */
class ChairService
{
    public function __construct(private AiProviderFactory $factory) {}

    public function decide(Chat $chat): ?ChatMessage
    {
        $chair = Persona::query()
            ->where('is_chair', true)
            ->where('is_active', true)
            ->with('aiModel.provider')
            ->first();

        if (! $chair || ! $chair->aiModel) {
            return null;
        }

        $summary = $chat->latestScribeSummary();
        $basis = $summary
            ? trim((string) $summary->summary)
            : $chat->messages()
                ->where('role', ChatMessage::ROLE_PERSONA)
                ->orderBy('id')
                ->pluck('content')
                ->implode("\n\n");

        if (trim($basis) === '') {
            return null;
        }

        if (filled($chat->constraints)) {
            $basis .= "\n\nTVRDA OGRANIČENJA koja odluka MORA poštovati:\n".trim((string) $chat->constraints);
        }

        try {
            $response = $this->factory->for($chair->aiModel)->sendMessage(
                $this->chairSystemPrompt($chat, $chair),
                [AiMessage::user("Sinteza rasprave:\n\n".$basis)],
                ['max_tokens' => 900, 'temperature' => 0.2],
            );
        } catch (Throwable) {
            return null;
        }

        if (trim($response->content) === '') {
            return null;
        }

        $cost = $chair->aiModel->calculateCost($response->inputTokens, $response->outputTokens);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $chair->id,
            'role' => ChatMessage::ROLE_PERSONA,
            'content' => trim($response->content),
            'round_number' => (int) $chat->current_round,
            'turn_number' => (int) ($chat->messages()->max('turn_number') ?? 1),
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
            'model_used' => $chair->aiModel->model_string,
            'provider_used' => $chair->aiModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => ['chair_decision' => true],
        ]);

        $chat->increment('total_messages');
        $chat->increment('total_input_tokens', $response->inputTokens);
        $chat->increment('total_output_tokens', $response->outputTokens);
        $chat->increment('total_cost', $cost);

        broadcast(new ChatMessageCreated($message));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $message;
    }

    private function chairSystemPrompt(Chat $chat, Persona $chair): string
    {
        return trim((string) $chair->system_prompt)
            ."\n\n--- ZADATAK ---\n"
            .'Rasprava je gotova. Iz sinteze izvuci JEDNU preporuku — ne opis, ne popis opcija. '
            ."Odgovori TOČNO ovim formatom i ničim drugim:\n"
            ."ODLUKA: [jedan konkretan put koji preporučaš]\n"
            ."RAZLOG: [zašto baš taj — 1-2 rečenice]\n"
            ."NAJVEĆI TRADE-OFF: [što se time žrtvuje]\n"
            ."PRVI KORAK: [prva konkretna akcija]\n"
            ."Budi odlučan, bez 'ovisi'. Ako rasprava nije konvergirala, ti svejedno presuđuješ. "
            .'Piši na jeziku: '.($chat->language ?: config('cortex.output_language', 'Croatian')).'.';
    }
}
