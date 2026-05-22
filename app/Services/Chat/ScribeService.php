<?php

namespace App\Services\Chat;

use App\Events\ChatCostUpdated;
use App\Events\ChatMessageCreated;
use App\Events\ScribeSummarized;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Models\ScribeSummary;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use App\Services\Billing\WalletService;

class ScribeService
{
    public function __construct(
        private AiProviderFactory $factory,
        private KnowledgeService $knowledge,
        private WalletService $walletService,
    ) {}

    /**
     * Produce an interim summary when the round-based or message-count cadence
     * is due. The guaranteed final synthesis is handled by summarize(.., true).
     */
    public function maybeSummarize(Chat $chat, int $round): ?ScribeSummary
    {
        $sinceId = (int) ($chat->latestScribeSummary()?->to_message_id ?? 0);

        $newCount = $chat->messages()
            ->where('id', '>', $sinceId)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_PERSONA])
            ->count();

        if ($newCount === 0) {
            return null;
        }

        $roundInterval = max(1, (int) config('cortex.scribe_round_interval', 2));
        $countInterval = max(1, (int) $chat->scribe_interval);

        if ($round % $roundInterval !== 0 && $newCount < $countInterval) {
            return null;
        }

        return $this->summarize($chat);
    }

    /**
     * Summarize the discussion. A final synthesis ($final = true) is cumulative
     * over the whole chat; an interim summary covers only messages since the last.
     */
    public function summarize(Chat $chat, bool $final = false): ?ScribeSummary
    {
        $scribe = Persona::query()
            ->where('is_scribe', true)
            ->where('is_active', true)
            ->with('aiModel.provider')
            ->first();

        if (! $scribe || ! $scribe->aiModel) {
            return null;
        }

        // The final synthesis is cumulative over the whole discussion.
        $sinceId = $final ? 0 : (int) ($chat->latestScribeSummary()?->to_message_id ?? 0);

        $messages = $chat->messages()
            ->with('persona:id,name')
            ->where('id', '>', $sinceId)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_PERSONA])
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        $transcript = $messages
            ->map(fn (ChatMessage $m) => '['.($m->role === ChatMessage::ROLE_USER ? 'Korisnik' : ($m->persona?->name ?? 'Persona')).']: '.trim((string) $m->content))
            ->implode("\n\n");

        // Scribe runs on the chat owner's wallet — bill it the same way as
        // a persona contribution. Reserve up-front so the owner can't sneak
        // past the budget by spawning long discussions.
        $wallet = $this->walletService->forUser($chat->user);
        $margin = $this->walletService->marginMultiplierFor($chat->user);
        $estimatedUserCost = $this->estimateScribeUserCost($scribe->aiModel, $margin);

        $reserve = $this->walletService->reserve(
            $wallet,
            $estimatedUserCost,
            $scribe->aiModel,
            sourceType: 'chat',
            sourceId: $chat->id,
            metadata: ['role' => 'scribe', 'final' => $final],
        );

        $adapter = $this->factory->for($scribe->aiModel);

        try {
            $response = $adapter->sendMessage(
                $this->scribeSystemPrompt($chat, $scribe, $final),
                [AiMessage::user("Sažmi sljedeću raspravu boardrooma:\n\n".$transcript)],
                ['max_tokens' => (int) config('cortex.scribe_max_tokens', 2200), 'temperature' => 0.3],
            );
        } catch (\Throwable $e) {
            $this->walletService->release($reserve, 'scribe_call_failed');
            throw $e;
        }

        $providerCost = (float) $adapter->calculateCost($response->inputTokens, $response->outputTokens);
        $userCost = round($providerCost * $margin, 6);
        $parsed = $this->parseScribeOutput($response->content);

        // Settle the wallet hold. An empty scribe response still gets debited —
        // the model did the work even if our prompt failed to coax a parseable JSON.
        if ($response->outputTokens > 0) {
            $settled = $this->walletService->commitDebit(
                $reserve,
                providerCost: $providerCost,
                actualUserCost: $userCost,
                sourceType: 'chat',
                sourceId: $chat->id,
                metadata: ['role' => 'scribe', 'final' => $final, 'margin' => $margin],
            );
            $walletTxId = $settled['debit']->id;
        } else {
            $this->walletService->release($reserve, 'scribe_empty_response');
            $providerCost = 0.0;
            $userCost = 0.0;
            $walletTxId = null;
        }

        // Legacy `cost` column on scribe_summaries keeps provider_cost for
        // back-compat with existing dashboards.
        $cost = $providerCost;

        $summary = ScribeSummary::create([
            'chat_id' => $chat->id,
            'persona_id' => $scribe->id,
            'from_message_id' => $messages->first()->id,
            'to_message_id' => $messages->last()->id,
            'summary' => $parsed['summary'],
            'key_decisions' => $parsed['key_decisions'],
            'key_ideas' => $parsed['key_ideas'],
            'open_questions' => $parsed['open_questions'],
            'action_items' => $parsed['action_items'],
            'assumptions_to_validate' => $parsed['assumptions_to_validate'],
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
        ]);

        // The scribe contributes durable, generalizable insights to global memory.
        $this->knowledge->capture($chat, $parsed['durable_insights']);

        $scribeMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'persona_id' => $scribe->id,
            'role' => ChatMessage::ROLE_SCRIBE,
            'content' => $parsed['summary'],
            'round_number' => (int) $chat->current_round,
            'turn_number' => (int) $messages->last()->turn_number,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost' => $cost,
            'is_billable' => $walletTxId !== null,
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'finish_reason' => $response->finishReason ?? null,
            'wallet_transaction_id' => $walletTxId,
            'model_used' => $scribe->aiModel->model_string,
            'provider_used' => $scribe->aiModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => ['scribe_summary_id' => $summary->id, 'final' => $final, 'margin' => $margin],
        ]);

        $chat->increment('total_messages');
        $chat->increment('total_input_tokens', $response->inputTokens);
        $chat->increment('total_output_tokens', $response->outputTokens);
        $chat->increment('total_cost', $cost);
        $chat->update(['last_scribe_summary_at' => now()]);

        // Broadcasting a large synthesis can fail (payload limits, Reverb
        // down) — it must never abort the discussion; the data is persisted.
        try {
            broadcast(new ChatMessageCreated($scribeMessage));
            broadcast(new ScribeSummarized($summary));
            broadcast(new ChatCostUpdated($chat->refresh()));
        } catch (\Throwable $e) {
            report($e);
        }

        return $summary;
    }

    /**
     * Pre-flight estimate for a scribe call — much bigger output budget than
     * a persona since scribe writes the full structured synthesis. Uses the
     * scribe_max_tokens config as the upper bound times a small safety bias.
     */
    private function estimateScribeUserCost(\App\Models\AiModel $model, float $margin): float
    {
        $estimatedInputTokens = (int) config('cortex.context_message_limit', 30) * 400;
        $estimatedOutputTokens = (int) config('cortex.scribe_max_tokens', 2200);

        $providerEstimate = (float) $model->calculateCost($estimatedInputTokens, $estimatedOutputTokens);

        return max(0.0001, round($providerEstimate * $margin * 1.1, 6));
    }

    /**
     * The scribe persona prompt plus a strict JSON output contract.
     */
    private function scribeSystemPrompt(Chat $chat, Persona $scribe, bool $final = false): string
    {
        $prompt = trim((string) $scribe->system_prompt)
            ."\n\n--- IZLAZNI FORMAT ---\n"
            ."Vrati ISKLJUČIVO valjani JSON objekt (bez markdown ograda, bez teksta okolo) s točno ovim ključevima:\n"
            .'{"summary": "strukturirani tekstualni sažetak prema tvom propisanom formatu (TEMA, KLJUČNE IDEJE, ODLUKE, NESLAGANJA, OTVORENA PITANJA, SLJEDEĆI KORACI, PRIORITETNA MATRICA)", '
            .'"key_ideas": [{"idea": "sažeto formulirana ključna ideja", "contributing_personas": ["točno ime persone koja je ideju iznijela ili razvila"]}], '
            .'"key_decisions": ["donesena odluka"], '
            .'"open_questions": ["otvoreno pitanje"], '
            .'"action_items": ["konkretan sljedeći korak"], '
            .'"assumptions_to_validate": ["tvrdnja, brojka ili statistika koju je persona iznijela a treba ju provjeriti nad stvarnim podacima prije korištenja"], '
            .'"durable_insights": ["trajni, generalizabilni uvid ili lekcija iz ove rasprave koji vrijedi i izvan ove konkretne teme"]}'."\n"
            .'"key_ideas" je niz objekata s ključevima "idea" (string) i "contributing_personas" '
            .'(niz točnih imena persona iz transkripta — oznaka [Ime] na početku svakog doprinosa); to '
            .'omogućuje kasniju analizu koja persona donosi vrijedne ideje. Ostali ključevi osim "summary" '
            .'su nizovi stringova. Ako za neki ključ nema sadržaja, vrati prazan niz []. '
            .'U dijelu PRIORITETNA MATRICA svrstaj svaku ključnu ideju u točno jedan kvadrant Impact x Effort: '
            .'BRZE POBJEDE (velik učinak, malo truda), VELIKE OKLADE (velik učinak, puno truda), '
            .'SPOREDNO (mali učinak, malo truda), PRESKOČITI (mali učinak, puno truda). '
            .'Cijeli izlaz (summary i sve nizove) napiši na jeziku: '
            .($chat->language ?: config('cortex.output_language', 'Croatian')).'.';

        if ($final) {
            $prompt .= "\nOvo je ZAVRŠNA sinteza cijele rasprave. U dijelu ODLUKE unutar \"summary\" jasno navedi "
                .'je li rasprava došla do konkretnog zaključka ili je ostala bez jasne konvergencije.';
        }

        return $prompt;
    }

    /**
     * Parse the scribe's JSON output, tolerating markdown fences and stray text.
     *
     * @return array{summary: string, key_ideas: list<array{idea: string, contributing_personas: array<int, string>}>, key_decisions: array<int, string>, open_questions: array<int, string>, action_items: array<int, string>, assumptions_to_validate: array<int, string>, durable_insights: array<int, string>}
     */
    private function parseScribeOutput(string $raw): array
    {
        $fallback = [
            'summary' => trim($raw),
            'key_ideas' => [],
            'key_decisions' => [],
            'open_questions' => [],
            'action_items' => [],
            'assumptions_to_validate' => [],
            'durable_insights' => [],
        ];

        $text = trim((string) preg_replace('/```(?:json)?/i', '', trim($raw)));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return $fallback;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return $fallback;
        }

        $strings = static fn ($value): array => array_values(array_filter(
            array_map(
                static fn ($item) => is_string($item) ? trim($item) : (is_scalar($item) ? (string) $item : ''),
                is_array($value) ? $value : [],
            ),
            static fn (string $item) => $item !== '',
        ));

        return [
            'summary' => isset($decoded['summary']) && is_string($decoded['summary']) && trim($decoded['summary']) !== ''
                ? trim($decoded['summary'])
                : trim($raw),
            'key_ideas' => $this->parseKeyIdeas($decoded['key_ideas'] ?? [], $strings),
            'key_decisions' => $strings($decoded['key_decisions'] ?? []),
            'open_questions' => $strings($decoded['open_questions'] ?? []),
            'action_items' => $strings($decoded['action_items'] ?? []),
            'assumptions_to_validate' => $strings($decoded['assumptions_to_validate'] ?? []),
            'durable_insights' => $strings($decoded['durable_insights'] ?? []),
        ];
    }

    /**
     * Normalise key_ideas into {idea, contributing_personas} objects so each
     * idea carries its persona attribution. Tolerates the legacy string shape.
     *
     * @param  callable(mixed): array<int, string>  $strings
     * @return list<array{idea: string, contributing_personas: array<int, string>}>
     */
    private function parseKeyIdeas(mixed $value, callable $strings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ideas = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $idea = trim($item);
            } elseif (is_array($item)) {
                $idea = isset($item['idea']) && is_string($item['idea']) ? trim($item['idea']) : '';
            } else {
                continue;
            }

            if ($idea === '') {
                continue;
            }

            $ideas[] = [
                'idea' => $idea,
                'contributing_personas' => is_array($item) ? $strings($item['contributing_personas'] ?? []) : [],
            ];
        }

        return $ideas;
    }
}
