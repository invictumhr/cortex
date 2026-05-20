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

        $context = '';

        if (filled($chat->context)) {
            $context .= "=== POZNATI KONTEKST (činjenice o korisnikovom sustavu — uzmi zdravo za gotovo) ===\n"
                .mb_substr(trim((string) $chat->context), 0, 12000)."\n\n";
        }

        if (filled($chat->constraints)) {
            $context .= "=== TVRDA OGRANIČENJA (OBAVEZNO poštuj — prijedlog koji ih krši je bezvrijedan) ===\n"
                .trim((string) $chat->constraints)."\n\n";
        }

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
            $prompt .= 'Korisnik je zadao TVRDA OGRANIČENJA (navedena u kontekstu poruke). Nijedan tvoj prijedlog ih ne smije '
                ."kršiti — ako bi ideja prekršila ograničenje, ne iznosi je, nego predloži rješenje unutar granica.\n";
        }

        return $prompt
            ."Intelektualno poštenje (OBAVEZNO): NE izmišljaj konkretne brojke, statistike, postotke, benchmarke ni rezultate. "
            ."Ne tvrdi da si proveo analizu, istrenirao model, pokrenuo upit ili nešto izmjerio — nemaš pristup stvarnim podacima. "
            ."Prijedloge iznosi kao hipoteze i obrazloženo razmišljanje; ono što tek treba izmjeriti izrijekom označi kao 'za provjeru'. "
            ."Ne pretpostavljaj činjenice o korisnikovoj situaciji koje nisu navedene (veličina tima, postojeći sustavi, budžet, "
            ."promet) — ako ti podatak nedostaje, reci to ili postavi pitanje umjesto da ga izmisliš.\n"
            .'JEZIK ODGOVORA (OBAVEZNO, nadjačava sve jezične upute iz tvog karaktera): pišeš ISKLJUČIVO na jeziku: '
            .($chat->language ?: config('cortex.deliberation_language', 'English')).'.';
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
