<?php

namespace App\Services\Chat;

use App\Events\ChatCostUpdated;
use App\Events\ChatMessageCreated;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use App\Services\Billing\WalletService;
use Throwable;

/**
 * After a discussion ends, the Chair forces one decision out of the scribe's
 * synthesis — turning a menu of ideas into a single recommended path.
 */
class ChairService
{
    public function __construct(
        private AiProviderFactory $factory,
        private WalletService $walletService,
    ) {}

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

        // Bill the chair to the chat owner's wallet — same reserve/commit/release
        // pattern as personas. Chair output is small (max 900 tokens), so the
        // estimate stays tight.
        $wallet = $this->walletService->forUser($chat->user);
        $margin = $this->walletService->marginMultiplierFor($chat->user);
        $estimatedUserCost = $this->estimateChairUserCost($chair->aiModel, $margin);

        $reserve = $this->walletService->reserve(
            $wallet,
            $estimatedUserCost,
            $chair->aiModel,
            sourceType: 'chat',
            sourceId: $chat->id,
            metadata: ['role' => 'chair'],
        );

        try {
            $response = $this->factory->for($chair->aiModel)->sendMessage(
                $this->chairSystemPrompt($chat, $chair),
                [AiMessage::user("Sinteza rasprave:\n\n".$basis)],
                ['max_tokens' => 900, 'temperature' => 0.2],
            );
        } catch (Throwable) {
            $this->walletService->release($reserve, 'chair_call_failed');

            return null;
        }

        if (trim($response->content) === '') {
            $this->walletService->release($reserve, 'chair_empty_response');

            return null;
        }

        $providerCost = (float) $chair->aiModel->calculateCost($response->inputTokens, $response->outputTokens);
        $userCost = round($providerCost * $margin, 6);

        $settled = $this->walletService->commitDebit(
            $reserve,
            providerCost: $providerCost,
            actualUserCost: $userCost,
            sourceType: 'chat',
            sourceId: $chat->id,
            metadata: ['role' => 'chair', 'margin' => $margin],
        );

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $chair->id,
            'role' => ChatMessage::ROLE_PERSONA,
            'content' => trim($response->content),
            'round_number' => (int) $chat->current_round,
            'turn_number' => (int) ($chat->messages()->max('turn_number') ?? 1),
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $providerCost,
            'is_billable' => true,
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'finish_reason' => $response->finishReason ?? null,
            'wallet_transaction_id' => $settled['debit']->id,
            'model_used' => $chair->aiModel->model_string,
            'provider_used' => $chair->aiModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => ['chair_decision' => true, 'margin' => $margin],
        ]);

        $chat->increment('total_messages');
        $chat->increment('total_input_tokens', $response->inputTokens);
        $chat->increment('total_output_tokens', $response->outputTokens);
        $chat->increment('total_cost', $providerCost);

        broadcast(new ChatMessageCreated($message));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $message;
    }

    /**
     * Pre-flight estimate for a chair call — chair is bounded to 900 output
     * tokens by prompt design, so this is tight by construction.
     */
    private function estimateChairUserCost(\App\Models\AiModel $model, float $margin): float
    {
        $estimatedInputTokens = 4000; // scribe summary + constraints
        $estimatedOutputTokens = 900;

        $providerEstimate = (float) $model->calculateCost($estimatedInputTokens, $estimatedOutputTokens);

        return max(0.0001, round($providerEstimate * $margin * 1.1, 6));
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
