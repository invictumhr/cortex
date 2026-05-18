<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeNote;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;

/**
 * The global, ever-growing knowledge memory. The scribe distils durable
 * insights from each discussion; they accumulate here and can be consolidated
 * into a single digest that future discussions may opt into.
 */
class KnowledgeService
{
    public function __construct(private AiProviderFactory $factory) {}

    /**
     * Persist durable insights the scribe extracted from a discussion.
     *
     * @param  array<int, string>  $insights
     */
    public function capture(Chat $chat, array $insights): void
    {
        $stored = 0;

        foreach ($insights as $insight) {
            $insight = trim((string) $insight);

            if ($insight === '') {
                continue;
            }

            KnowledgeNote::create([
                'chat_id' => $chat->id,
                'content' => $insight,
                'source' => 'scribe',
            ]);
            $stored++;
        }

        if ($stored > 0) {
            KnowledgeBase::current()->increment('notes_count', $stored);
        }
    }

    public function digest(): string
    {
        return trim((string) KnowledgeBase::current()->digest);
    }

    /**
     * Consolidate every accumulated note into one organised digest.
     */
    public function rebuildDigest(): KnowledgeBase
    {
        $base = KnowledgeBase::current();

        $notes = KnowledgeNote::query()->orderByDesc('id')->limit(300)->pluck('content');

        if ($notes->isEmpty()) {
            return $base;
        }

        $scribe = Persona::query()
            ->where('is_scribe', true)
            ->where('is_active', true)
            ->with('aiModel.provider')
            ->first();

        if (! $scribe || ! $scribe->aiModel) {
            return $base;
        }

        $response = $this->factory->for($scribe->aiModel)->sendMessage(
            'Ti si Cortex zapisničar. Konsolidiraj sljedeće zabilješke iz mnogih rasprava u sažet, '
            .'organiziran pregled trajnog znanja. Grupiraj po temama, izbaci duplikate, zadrži samo '
            .'generalizabilne i korisne uvide. Ne izmišljaj ništa. Piši na hrvatskom jeziku.',
            [AiMessage::user("Zabilješke:\n".$notes->map(fn ($n) => '- '.$n)->implode("\n"))],
            ['max_tokens' => 1800, 'temperature' => 0.3],
        );

        $base->update([
            'digest' => trim($response->content),
            'digested_at' => now(),
        ]);

        return $base->refresh();
    }
}
