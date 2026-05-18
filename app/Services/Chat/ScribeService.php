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

class ScribeService
{
    public function __construct(
        private AiProviderFactory $factory,
        private KnowledgeService $knowledge,
    ) {}

    /**
     * Summarize the chat once enough messages have accrued since the last summary.
     */
    public function maybeSummarize(Chat $chat): ?ScribeSummary
    {
        $interval = max(1, (int) $chat->scribe_interval);
        $sinceId = (int) ($chat->latestScribeSummary()?->to_message_id ?? 0);

        $newCount = $chat->messages()
            ->where('id', '>', $sinceId)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_PERSONA])
            ->count();

        if ($newCount < $interval) {
            return null;
        }

        return $this->summarize($chat);
    }

    public function summarize(Chat $chat): ?ScribeSummary
    {
        $scribe = Persona::query()
            ->where('is_scribe', true)
            ->where('is_active', true)
            ->with('aiModel.provider')
            ->first();

        if (! $scribe || ! $scribe->aiModel) {
            return null;
        }

        $sinceId = (int) ($chat->latestScribeSummary()?->to_message_id ?? 0);

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

        $adapter = $this->factory->for($scribe->aiModel);

        $response = $adapter->sendMessage(
            $this->scribeSystemPrompt($scribe),
            [AiMessage::user("Sažmi sljedeću raspravu boardrooma:\n\n".$transcript)],
            ['max_tokens' => (int) config('cortex.scribe_max_tokens', 2200), 'temperature' => 0.3],
        );

        $cost = $adapter->calculateCost($response->inputTokens, $response->outputTokens);
        $parsed = $this->parseScribeOutput($response->content);

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
            'model_used' => $scribe->aiModel->model_string,
            'provider_used' => $scribe->aiModel->provider?->name,
            'response_time_ms' => $response->responseTimeMs,
            'metadata' => ['scribe_summary_id' => $summary->id],
        ]);

        $chat->increment('total_messages');
        $chat->increment('total_input_tokens', $response->inputTokens);
        $chat->increment('total_output_tokens', $response->outputTokens);
        $chat->increment('total_cost', $cost);
        $chat->update(['last_scribe_summary_at' => now()]);

        broadcast(new ChatMessageCreated($scribeMessage));
        broadcast(new ScribeSummarized($summary));
        broadcast(new ChatCostUpdated($chat->refresh()));

        return $summary;
    }

    /**
     * The scribe persona prompt plus a strict JSON output contract.
     */
    private function scribeSystemPrompt(Persona $scribe): string
    {
        return trim((string) $scribe->system_prompt)
            ."\n\n--- IZLAZNI FORMAT ---\n"
            ."Vrati ISKLJUČIVO valjani JSON objekt (bez markdown ograda, bez teksta okolo) s točno ovim ključevima:\n"
            .'{"summary": "strukturirani tekstualni sažetak prema tvom propisanom formatu (TEMA, KLJUČNE IDEJE, ODLUKE, NESLAGANJA, OTVORENA PITANJA, SLJEDEĆI KORACI)", '
            .'"key_ideas": ["ključna ideja — tko ju je podržao, tko kritizirao"], '
            .'"key_decisions": ["donesena odluka"], '
            .'"open_questions": ["otvoreno pitanje"], '
            .'"action_items": ["konkretan sljedeći korak"], '
            .'"assumptions_to_validate": ["tvrdnja, brojka ili statistika koju je persona iznijela a treba ju provjeriti nad stvarnim podacima prije korištenja"], '
            .'"durable_insights": ["trajni, generalizabilni uvid ili lekcija iz ove rasprave koji vrijedi i izvan ove konkretne teme"]}'."\n"
            .'Sve vrijednosti osim "summary" su nizovi stringova; ako za neki ključ nema sadržaja, vrati prazan niz []. '
            .'Cijeli izlaz (summary i sve nizove) napiši na jeziku: '.config('cortex.output_language', 'Croatian').'.';
    }

    /**
     * Parse the scribe's JSON output, tolerating markdown fences and stray text.
     *
     * @return array{summary: string, key_ideas: array<int, string>, key_decisions: array<int, string>, open_questions: array<int, string>, action_items: array<int, string>, assumptions_to_validate: array<int, string>, durable_insights: array<int, string>}
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
            'key_ideas' => $strings($decoded['key_ideas'] ?? []),
            'key_decisions' => $strings($decoded['key_decisions'] ?? []),
            'open_questions' => $strings($decoded['open_questions'] ?? []),
            'action_items' => $strings($decoded['action_items'] ?? []),
            'assumptions_to_validate' => $strings($decoded['assumptions_to_validate'] ?? []),
            'durable_insights' => $strings($decoded['durable_insights'] ?? []),
        ];
    }
}
