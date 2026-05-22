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
use App\Services\Billing\WalletService;
use RuntimeException;
use Throwable;

class PersonaResponder
{
    public function __construct(
        private AiProviderFactory $factory,
        private ContextBuilder $contextBuilder,
        private WalletService $walletService,
    ) {}

    /**
     * Generate one persona contribution, persist it, bill it, and broadcast it.
     *
     * Flow: estimate cost → reserve from wallet → call provider (with fallback)
     * → if the response is billable, commit the actual debit and stamp the
     * wallet_transaction_id on the message; if not, release the reserve so the
     * user isn't charged for an empty/filtered/errored response.
     *
     * Throws InsufficientFundsException when the wallet can't even cover the
     * estimate — caller (GeneratePersonaResponse) surfaces that as a system
     * message in the chat.
     */
    public function respond(Chat $chat, Persona $persona, int $round, int $turn): ChatMessage
    {
        $primary = $this->modelFor($persona, $chat);

        if (! $primary) {
            throw new RuntimeException("Persona {$persona->name} nema dodijeljen AI model.");
        }

        $wallet = $this->walletService->forUser($chat->user);
        $margin = $this->walletService->marginMultiplierFor($chat->user);
        $estimatedUserCost = $this->estimateUserCost($primary, $margin);

        // Pre-flight hold. If this throws InsufficientFunds the persona never
        // even sees the brief — the caller turns it into a top-up nudge.
        $reserve = $this->walletService->reserve(
            $wallet,
            $estimatedUserCost,
            $primary,
            sourceType: 'chat',
            sourceId: $chat->id,
            metadata: ['persona_id' => $persona->id, 'round' => $round, 'turn' => $turn],
        );

        try {
            $callResult = $this->callWithFallback($chat, $persona, $primary);
        } catch (Throwable $e) {
            // Provider blew up before we got any output — refund the full hold
            // and re-raise. The job handler turns this into a system message.
            $this->walletService->release($reserve, 'persona_call_failed');
            throw $e;
        }

        $response = $callResult['response'];
        $usedModel = $callResult['usedModel'];

        $providerCost = (float) $usedModel->calculateCost($response->inputTokens, $response->outputTokens);
        $userCost = round($providerCost * $margin, 6);

        // Empty / filtered output gets logged as a non-billable persona row so
        // the discussion thread is honest, then the reserve is released in full.
        $isBillable = $this->isBillable($response);

        if (! $isBillable) {
            $this->walletService->release($reserve, 'non_billable_response: '.$response->finishReason);
            $providerCost = 0.0;
            $userCost = 0.0;
            $walletTxId = null;
        } else {
            $settled = $this->walletService->commitDebit(
                $reserve,
                providerCost: $providerCost,
                actualUserCost: $userCost,
                sourceType: 'chat',
                sourceId: $chat->id,
                metadata: [
                    'persona_id' => $persona->id,
                    'model' => $usedModel->model_string,
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'finish_reason' => $response->finishReason,
                    'margin' => $margin,
                ],
            );
            $walletTxId = $settled['debit']->id;
        }

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $persona->id,
            'role' => ChatMessage::ROLE_PERSONA,
            'content' => $response->content,
            'round_number' => $round,
            'turn_number' => $turn,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            // Legacy `cost` field still mirrors provider_cost for back-compat
            // with the old chat dashboards that haven't moved to user_cost yet.
            'cost' => $providerCost,
            'is_billable' => $isBillable,
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'finish_reason' => $response->finishReason,
            'wallet_transaction_id' => $walletTxId,
            'model_used' => $usedModel->model_string,
            'provider_used' => $usedModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => array_filter([
                'finish_reason' => $response->finishReason,
                'fallback_from' => $usedModel->id === $primary->id ? null : $primary->model_string,
                'margin_multiplier' => $margin,
            ], static fn ($value) => $value !== null),
        ]);

        // Aggregate chat totals stay in provider-cost terms — that's what the
        // existing scribe/UI math expects. user_cost is a separate roll-up via
        // the wallet ledger.
        $this->recordUsage($chat, $persona, $response->inputTokens, $response->outputTokens, $providerCost);

        broadcast(new ChatMessageCreated($message));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $message;
    }

    /**
     * Try the persona's primary model, then the configured fallback. Returns
     * the first non-empty response or throws if both attempts failed.
     *
     * @return array{response: \App\Services\Ai\Data\AiResponse, usedModel: AiModel}
     */
    private function callWithFallback(Chat $chat, Persona $persona, AiModel $primary): array
    {
        [$system, $messages, $options] = $this->contextBuilder->build($chat, $persona);

        $attempts = [$primary];
        if ($fallback = $this->fallbackModel($primary)) {
            $attempts[] = $fallback;
        }

        $failure = 'nepoznata greška';

        foreach ($attempts as $model) {
            try {
                $candidate = $this->factory->for($model)->sendMessage($system, $messages, $options);

                if (trim($candidate->content) !== '') {
                    return ['response' => $candidate, 'usedModel' => $model];
                }

                $failure = "model {$model->model_string} vratio prazan odgovor";
            } catch (Throwable $e) {
                $failure = "model {$model->model_string}: ".$e->getMessage();
            }
        }

        throw new RuntimeException('ni primarni ni fallback model nisu odgovorili — '.$failure);
    }

    /**
     * Pre-flight estimate: assume p90-ish input + output token budget at the
     * primary model's posted rates, then apply the user's margin tier. The
     * panel said use historical p90 once we have data; for cold-start we use
     * the configurable fallback.
     */
    private function estimateUserCost(AiModel $model, float $margin): float
    {
        // Input tokens we control loosely (system + context budget). For
        // pre-flight we use a fixed cap so estimate is stable across calls.
        $estimatedInputTokens = (int) config('cortex.context_message_limit', 30) * 200;
        $estimatedOutputTokens = (int) config('cortex.billing.estimate_fallback_output_tokens', 800);

        $providerEstimate = (float) $model->calculateCost($estimatedInputTokens, $estimatedOutputTokens);

        // Bias slightly upward (×1.1) so honest p90 doesn't trip
        // InsufficientFunds when the actual lands at p55-ish.
        $estimate = $providerEstimate * $margin * 1.1;

        return max(0.0001, round($estimate, 6));
    }

    /**
     * Did the provider deliver something worth charging for? Strict criteria
     * the panel agreed on: real output tokens, finish_reason wasn't a refusal,
     * and we got past parsing.
     */
    private function isBillable(\App\Services\Ai\Data\AiResponse $response): bool
    {
        if ($response->outputTokens <= 0) {
            return false;
        }

        // Content filter refusals deliver tokens we don't want to bill — the
        // user didn't get usable output and the provider already charged us
        // for almost nothing.
        if (in_array($response->finishReason, ['content_filter', 'safety', 'blocked'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Pick the AI model a persona runs on for THIS chat. Resolution chain:
     *
     *   1. Per-chat per-persona override stored in `chat_personas.ai_model_id`
     *      (set by BoardroomComposer or the user's custom panel picker)
     *   2. The persona's own default `ai_model_id` (legacy 1:1 mapping)
     *   3. Configured fallback model (`cortex.fallback_model`)
     *
     * `chat->strong` flagship upgrade is applied AFTER resolution so a custom
     * pairing still respects the strong flag when present.
     *
     * Public so CostEstimator can price a run with the same model selection.
     */
    public function modelFor(Persona $persona, Chat $chat): ?AiModel
    {
        // Step 1: per-chat override from the pivot. Looked up freshly to
        // avoid stale relations after BoardroomComposer attaches mid-flight.
        $base = $this->resolvePivotModel($chat, $persona)
            ?? $persona->aiModel
            ?? $this->fallbackPick();

        if (! $base) {
            return null;
        }

        // Step 2: strong-mode flagship upgrade — same logic as before.
        if (! $chat->strong || ! $base->provider) {
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

    /** Look up `chat_personas.ai_model_id` for this (chat, persona) pair. */
    private function resolvePivotModel(Chat $chat, Persona $persona): ?AiModel
    {
        $pivot = ChatPersona::query()
            ->where('chat_id', $chat->id)
            ->where('persona_id', $persona->id)
            ->whereNotNull('ai_model_id')
            ->orderByDesc('id')
            ->first();

        if (! $pivot) {
            return null;
        }

        return AiModel::query()
            ->whereKey($pivot->ai_model_id)
            ->where('is_active', true)
            ->with('provider')
            ->first();
    }

    /** Last-resort model when neither pivot nor persona default exists. */
    private function fallbackPick(): ?AiModel
    {
        $modelString = (string) config('cortex.fallback_model', '');
        if ($modelString === '') {
            return null;
        }

        return AiModel::query()
            ->where('model_string', $modelString)
            ->where('is_active', true)
            ->with('provider')
            ->first();
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
