<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Services\Ai\Data\AiImage;
use App\Services\Ai\Data\AiMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ContextBuilder
{
    /**
     * Assemble the system prompt, transcript message and request options
     * for a single persona's next contribution.
     *
     * @return array{0: string, 1: array<int, AiMessage>, 2: array<string, mixed>}
     */
    public function build(Chat $chat, Persona $persona): array
    {
        $limit = (int) config('cortex.context_message_limit', 30);
        $summary = $chat->latestScribeSummary();

        $query = $chat->messages()
            ->with(['persona:id,name,avatar_emoji', 'attachments'])
            ->orderBy('id');

        if ($summary && $summary->to_message_id) {
            $query->where('id', '>', $summary->to_message_id);
        }

        $recent = $query->get()->slice(-$limit)->values();

        // Independent first round: in round 1 a persona does not see its peers'
        // round-1 messages, so opening takes stay diverse instead of converging.
        if ((int) $chat->current_round <= 1) {
            $recent = $recent->reject(fn (ChatMessage $message) => $message->role === ChatMessage::ROLE_PERSONA
                && (int) $message->round_number >= 1
                && $message->persona_id !== $persona->id
            )->values();
        }

        $lines = [];
        foreach ($recent as $message) {
            $line = '['.$this->speakerName($message).']: '.trim((string) $message->content);

            foreach ($message->attachments as $attachment) {
                if (filled($attachment->extracted_content) && in_array($attachment->type, [
                    ChatAttachment::TYPE_URL,
                    ChatAttachment::TYPE_DOCUMENT,
                    ChatAttachment::TYPE_POWERSHELL_OUTPUT,
                ], true)) {
                    $label = match ($attachment->type) {
                        ChatAttachment::TYPE_URL => 'Priložen URL ('.$attachment->url.')',
                        ChatAttachment::TYPE_POWERSHELL_OUTPUT => 'PowerShell izlaz',
                        default => 'Priložen dokument',
                    };
                    $line .= "\n  [".$label."]:\n  ".mb_substr(trim((string) $attachment->extracted_content), 0, 6000);
                }
            }

            $lines[] = $line;
        }

        $supportsVision = (bool) $persona->aiModel?->supports_vision;
        $images = $supportsVision ? $this->collectImages($recent) : [];

        // Size cap: the message-count limit doesn't bound the BYTE size of the
        // transcript (long messages + attachment extracts), so trim the oldest
        // lines until the whole block fits the configured token budget. The
        // scribe summary already covers what gets dropped.
        $lines = $this->trimToTokenBudget($lines, $summary?->summary);

        // Pinned context/constraints live in the SYSTEM prompt (see
        // systemPrompt()): they're identical for every round, which makes the
        // system block a stable prefix that provider-side prompt caching can
        // reuse. Only the volatile parts (summary, transcript) stay here.
        $context = '';

        if ($summary) {
            $context .= "SAŽETAK DOSADAŠNJE RASPRAVE (zapisničar Scribe):\n".trim((string) $summary->summary)."\n\n";
        }
        $context .= "DOSADAŠNJA RASPRAVA:\n".($lines === [] ? '(rasprava je tek započela)' : implode("\n\n", $lines));

        if (! $supportsVision && $this->hasImages($recent)) {
            $context .= "\n\n[Korisnik je priložio sliku. Tvoj model ne podržava analizu slika — osloni se na opise drugih persona.]";
        }

        $context .= "\n\n---\nTi si {$persona->name} ({$persona->title}). Doprinesi raspravi sljedećom porukom iz svoje perspektive.";

        return [
            $this->systemPrompt($chat, $persona),
            [new AiMessage(AiMessage::ROLE_USER, $context, $images)],
            [
                'max_tokens' => (int) config('cortex.persona_max_tokens', 1200),
                'temperature' => (float) config('cortex.persona_temperature', 0.8),
            ],
        ];
    }

    private function systemPrompt(Chat $chat, Persona $persona): string
    {
        $others = $chat->activePersonas()
            ->where('personas.id', '!=', $persona->id)
            ->where('is_scribe', false)
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        $roster = $others === [] ? 'trenutno nema drugih sudionika' : implode(', ', $others);

        $prompt = trim((string) $persona->system_prompt)
            ."\n\n--- CORTEX BOARDROOM ---\n"
            ."Sudjeluješ u Cortex boardroomu — strukturiranoj raspravi više AI persona o temi koju zadaje korisnik. "
            ."Ostali sudionici: {$roster}.\n"
            ."Pravila ponašanja: doprinesi KRATKO (2-6 rečenica, osim ako tema doista zahtijeva više), ostani u svojoj struci "
            ."i kutu gledanja, referiraj se na druge persone po imenu kada se slažeš ili osporavaš njihove ideje, gradi na "
            ."rečenome umjesto ponavljanja. Govori isključivo u svoje ime — nikada ne piši odgovore drugih persona.\n"
            ."Doprinosi SUPSTANCOM — tvoj karakter je kut gledanja i ekspertiza, ne kostim: preskoči teatralne geste, "
            ."uvodne fraze i performans osobnosti, idi ravno na sadržaj.\n";

        // Round 2+ is a debate, not parallel monologue: force each persona to
        // either reject a specific prior claim or add a genuinely new angle.
        $round = (int) $chat->current_round;
        $totalRounds = (int) $chat->rounds_per_turn;

        if ($round >= 2 && $round >= $totalRounds && $totalRounds >= 3) {
            $prompt .= "Ovo je ZAVRŠNI krug rasprave — vrijeme je za KONVERGENCIJU, ne za nove napade. Reci što panel "
                ."stvarno može prihvatiti kao zajednički stav, koja je najjača preporuka, i izrijekom označi što ostaje "
                ."neriješeno. Ne otvaraj nove teme.\n";
        } elseif ($round >= 2) {
            $prompt .= "Ovo NIJE prvi krug — pred sobom su doprinosi drugih persona. Tvoja poruka mora pomaknuti raspravu: "
                ."ili (a) imenuj konkretnu tvrdnju ili pretpostavku određene persone koju odbacuješ i obrazloži zašto je pogrešna "
                ."ili rizična, ili (b) uvedi novo ograničenje, rizik ili kut koji nitko još nije spomenuo. "
                ."Puko slaganje, sažimanje ili pristojno neslaganje bez konkretne mete ne računa se kao doprinos.\n";
        }

        if (filled($chat->constraints)) {
            $prompt .= 'Korisnik je zadao TVRDA OGRANIČENJA (navedena niže). Nijedan tvoj prijedlog ih ne smije '
                ."kršiti — ako bi ideja prekršila ograničenje, ne iznosi je, nego predloži rješenje unutar granica.\n";
        }

        $prompt .= "Intelektualno poštenje (OBAVEZNO): NE izmišljaj konkretne brojke, statistike, postotke, benchmarke ni rezultate. "
            ."Ne tvrdi da si proveo analizu, istrenirao model, pokrenuo upit ili nešto izmjerio — nemaš pristup stvarnim podacima. "
            ."Prijedloge iznosi kao hipoteze i obrazloženo razmišljanje; ono što tek treba izmjeriti izrijekom označi kao 'za provjeru'. "
            ."Ne pretpostavljaj činjenice o korisnikovoj situaciji koje nisu navedene (veličina tima, postojeći sustavi, budžet, "
            ."promet) — ako ti podatak nedostaje, reci to ili postavi pitanje umjesto da ga izmisliš.\n"
            .'JEZIK ODGOVORA (OBAVEZNO, nadjačava sve jezične upute iz tvog karaktera): pišeš ISKLJUČIVO na jeziku: '
            .($chat->language ?: config('cortex.deliberation_language', 'English')).'.';

        // Pinned per-chat payload comes LAST and is round-invariant, so the
        // whole system block stays byte-identical between rounds — that's what
        // lets provider-side prompt caching (AnthropicAdapter) hit on every
        // round after the first.
        if (filled($chat->context)) {
            $prompt .= "\n\n=== POZNATI KONTEKST (činjenice o korisnikovom sustavu — uzmi zdravo za gotovo) ===\n"
                .mb_substr(trim((string) $chat->context), 0, 12000);
        }

        if (filled($chat->constraints)) {
            $prompt .= "\n\n=== TVRDA OGRANIČENJA (OBAVEZNO poštuj — prijedlog koji ih krši je bezvrijedan) ===\n"
                .trim((string) $chat->constraints);
        }

        return $prompt;
    }

    /**
     * Drop the oldest transcript lines until the assembled context fits the
     * rough token budget (~4 chars per token). A marker line replaces what
     * was cut so personas know the transcript is partial.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function trimToTokenBudget(array $lines, ?string $summaryText): array
    {
        $budgetChars = max(4000, (int) config('cortex.context_token_budget', 24000) * 4);

        // The summary travels alongside the transcript in the same message —
        // count it against the budget but never trim it.
        $fixedChars = mb_strlen((string) $summaryText);

        $totalChars = $fixedChars;
        foreach ($lines as $line) {
            $totalChars += mb_strlen($line);
        }

        if ($totalChars <= $budgetChars) {
            return $lines;
        }

        $trimmed = false;
        while (count($lines) > 1 && $totalChars > $budgetChars) {
            $totalChars -= mb_strlen(array_shift($lines));
            $trimmed = true;
        }

        // A single line can still exceed the budget (giant attachment extract);
        // hard-truncate it rather than sending an unbounded prompt.
        if ($totalChars > $budgetChars && $lines !== []) {
            $lines[0] = mb_substr($lines[0], 0, max(1000, $budgetChars - $fixedChars));
            $trimmed = true;
        }

        if ($trimmed) {
            array_unshift($lines, '[Stariji dio rasprave je izostavljen zbog duljine — osloni se na Scribeov sažetak.]');
        }

        return $lines;
    }

    private function speakerName(ChatMessage $message): string
    {
        return match ($message->role) {
            ChatMessage::ROLE_USER => 'Korisnik',
            ChatMessage::ROLE_SCRIBE => 'Scribe (zapisničar)',
            ChatMessage::ROLE_SYSTEM => 'Sustav',
            default => $message->persona?->name ?? 'Persona',
        };
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     */
    private function hasImages(Collection $messages): bool
    {
        foreach ($messages as $message) {
            foreach ($message->attachments as $attachment) {
                if ($attachment->type === ChatAttachment::TYPE_IMAGE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, AiImage>
     */
    private function collectImages(Collection $messages): array
    {
        $images = [];
        $disk = Storage::disk(config('filesystems.default', 'local'));

        foreach ($messages as $message) {
            foreach ($message->attachments as $attachment) {
                if ($attachment->type !== ChatAttachment::TYPE_IMAGE || ! $attachment->file_path) {
                    continue;
                }

                try {
                    if (! $disk->exists($attachment->file_path)) {
                        continue;
                    }
                    $images[] = new AiImage(
                        $attachment->mime_type ?: 'image/jpeg',
                        base64_encode((string) $disk->get($attachment->file_path)),
                    );
                } catch (\Throwable) {
                    // Skip unreadable attachments rather than failing the whole turn.
                }

                if (count($images) >= 4) {
                    return $images;
                }
            }
        }

        return $images;
    }
}
