<?php

namespace App\Services\Chat;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Persona;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Data\AiMessage;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Designs a question-specific panel: instead of picking from the fixed roster,
 * a model invents orthogonal expert roles fit to the topic, persisted as
 * ephemeral personas used only for this one discussion.
 */
class PanelArchitect
{
    public function __construct(private AiProviderFactory $factory) {}

    /**
     * @return Collection<int, Persona>  Ephemeral personas, or empty on failure.
     */
    public function design(Chat $chat, string $topic): Collection
    {
        $architect = AiModel::query()
            ->where('model_string', config('cortex.architect_model', 'claude-sonnet-4-6'))
            ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('provider')
            ->first();

        if (! $architect) {
            return collect();
        }

        $brief = trim($topic);
        if (filled($chat->context)) {
            $brief .= "\n\nKONTEKST:\n".mb_substr(trim((string) $chat->context), 0, 4000);
        }
        if (filled($chat->constraints)) {
            $brief .= "\n\nOGRANIČENJA:\n".trim((string) $chat->constraints);
        }

        try {
            $response = $this->factory->for($architect)->sendMessage(
                $this->architectPrompt($chat),
                [AiMessage::user("PITANJE:\n".$brief)],
                ['max_tokens' => 1500, 'temperature' => 0.4],
            );
        } catch (Throwable) {
            return collect();
        }

        $roles = $this->parseRoles((string) $response->content);

        if (count($roles) < 3) {
            return collect();
        }

        return $this->persistRoles($chat, $roles);
    }

    private function architectPrompt(Chat $chat): string
    {
        $language = $chat->language ?: config('cortex.output_language', 'Croatian');

        return 'Ti si arhitekt panela stručnjaka. Za zadano pitanje osmisli 5 različitih stručnih ULOGA čija bi '
            .'rasprava najbolje pritisnula problem iz svih relevantnih kutova. Uloge moraju biti ortogonalne (bez '
            .'preklapanja); barem jedna mora biti skeptik/realist koji presuđuje izvedivost. Za svaku ulogu vrati '
            .'"title" (kratak naziv uloge, 1-3 riječi) i "brief" (2-3 rečenice u 2. licu: tko si, koji kut pritišćeš, '
            .'što je tvoja odgovornost, što NE radiš). Bez izmišljenih imena ljudi i bez teatralnog karaktera — samo '
            .'kognitivna uloga. "title" i "brief" napiši na jeziku: '.$language.'. '
            .'Vrati ISKLJUČIVO JSON niz: [{"title":"...","brief":"..."}, ...].';
    }

    /**
     * @return array<int, array{title: string, brief: string}>
     */
    private function parseRoles(string $raw): array
    {
        if (! preg_match('/\[.*\]/s', $raw, $matched)) {
            return [];
        }

        $decoded = json_decode($matched[0], true);

        if (! is_array($decoded)) {
            return [];
        }

        $roles = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $brief = trim((string) ($item['brief'] ?? ''));
            if ($title !== '' && $brief !== '') {
                $roles[] = ['title' => mb_substr($title, 0, 60), 'brief' => $brief];
            }
        }

        return array_slice($roles, 0, 6);
    }

    /**
     * @param  array<int, array{title: string, brief: string}>  $roles
     * @return Collection<int, Persona>
     */
    private function persistRoles(Chat $chat, array $roles): Collection
    {
        $models = AiModel::query()
            ->whereIn('model_string', (array) config('cortex.architect_panel_models', []))
            ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
            ->get()
            ->values();

        if ($models->isEmpty()) {
            return collect();
        }

        $ids = [];

        foreach (array_values($roles) as $i => $role) {
            $ids[] = Persona::create([
                'name' => $role['title'],
                'slug' => 'eph-'.$chat->id.'-'.($i + 1),
                'title' => $role['title'],
                'avatar_emoji' => '🧩',
                'avatar_color' => '#64748b',
                'description' => 'Uloga generirana za raspravu #'.$chat->id.'.',
                'system_prompt' => $role['brief'],
                'ai_model_id' => $models[$i % $models->count()]->id,
                'personality_traits' => [],
                'expertise_areas' => [],
                'communication_style' => 'formal',
                'is_scribe' => false,
                'is_chair' => false,
                'is_ephemeral' => true,
                'is_active' => true,
                'sort_order' => 900 + $i,
            ])->id;
        }

        return Persona::whereIn('id', $ids)
            ->with('aiModel.provider')
            ->orderBy('sort_order')
            ->get();
    }
}
