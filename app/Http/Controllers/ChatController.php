<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Persona;
use App\Models\PowerShellPermission;
use App\Services\LanguageDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Chats/Index', [
            // Archived chats are filtered out of the sidebar list — they stay
            // in the DB and the chat URL still works if you have it bookmarked,
            // but they don't clutter the active workspace.
            'chats' => $request->user()->chats()
                ->where('status', '!=', Chat::STATUS_ARCHIVED)
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'status', 'total_messages', 'total_cost', 'updated_at']),
            'personas' => $this->speakerPersonas(),
            'models'   => $this->availableModels(),
        ]);
    }

    public function archive(Request $request, Chat $chat)
    {
        Gate::authorize('update', $chat);
        $chat->update(['status' => Chat::STATUS_ARCHIVED]);

        // AJAX caller already removed the row optimistically — give it a tiny
        // 200 OK instead of bouncing it through /chats redirect (axios would
        // follow that and pull the whole sidebar HTML back for nothing).
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('chats.index');
    }

    public function show(Request $request, Chat $chat): Response
    {
        Gate::authorize('view', $chat);

        $chat->load(['personas' => fn ($q) => $q->with('aiModel:id,name,model_string')->orderBy('sort_order')]);

        return Inertia::render('Chats/Show', [
            'chat' => $chat,
            'messages' => $chat->messages()
                ->with(['persona:id,name,title,avatar_emoji,avatar_color,is_scribe', 'attachments'])
                ->orderBy('id')
                ->get(),
            'scribeSummaries' => $chat->scribeSummaries()->orderBy('id')->get(),
            'allPersonas' => $this->speakerPersonas()
                ->concat($chat->personas->where('is_ephemeral', true))
                ->values(),
            'chats' => $request->user()->chats()
                ->where('status', '!=', Chat::STATUS_ARCHIVED)
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'status', 'updated_at']),
            'powershellEnabled' => (bool) PowerShellPermission::current()->is_enabled,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'string', 'max:20000'],
            'constraints' => ['nullable', 'string', 'max:5000'],
            'strong' => ['boolean'],
            'language' => ['nullable', 'string', 'in:'.implode(',', LanguageDetector::supportedIsoCodes())],
            // Three init modes — one of these three is filled, the others empty.
            'init_mode' => ['required', 'in:quick,custom_models,custom'],
            'agents' => ['nullable', 'integer', 'min:2', 'max:8'],
            'model_ids' => ['nullable', 'array'],
            'model_ids.*' => ['integer', 'exists:ai_models,id'],
            'pairs' => ['nullable', 'array'],
            'pairs.*.persona_id' => ['required_with:pairs', 'integer', 'exists:personas,id'],
            'pairs.*.ai_model_id' => ['required_with:pairs', 'integer', 'exists:ai_models,id'],
        ]);

        $initMode = $validated['init_mode'];
        $panelConfig = match ($initMode) {
            Chat::INIT_QUICK         => ['agents' => max(2, min(8, (int) ($validated['agents'] ?? 5)))],
            Chat::INIT_CUSTOM_MODELS => ['models' => array_values((array) ($validated['model_ids'] ?? []))],
            Chat::INIT_CUSTOM        => ['pairs' => array_values((array) ($validated['pairs'] ?? []))],
            default                  => [],
        };

        $chat = $request->user()->chats()->create([
            'title' => $validated['title'] ?: 'Novi boardroom',
            'description' => $validated['description'] ?? null,
            'context' => $validated['context'] ?? null,
            'constraints' => $validated['constraints'] ?? null,
            'strong' => $validated['strong'] ?? false,
            'init_mode' => $initMode,
            'panel_config' => $panelConfig,
            // Custom mode is composed at creation; quick/custom_models defer
            // until the first user message lands (BoardroomComposer runs in
            // ChatOrchestrator::sendUserMessage).
            'panel_composed' => $initMode === Chat::INIT_CUSTOM,
            'rounds_per_turn' => 1,
            'continuous' => true,
            'language' => LanguageDetector::fromIso($validated['language'] ?? 'en'),
            'scribe_interval' => (int) config('cortex.default_scribe_interval', 50),
            'status' => Chat::STATUS_PAUSED,
        ]);

        if ($initMode === Chat::INIT_CUSTOM) {
            foreach ($panelConfig['pairs'] as $pair) {
                $chat->personas()->attach($pair['persona_id'], [
                    'ai_model_id' => $pair['ai_model_id'],
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }
        }

        return redirect()->route('chats.show', $chat);
    }

    public function destroy(Request $request, Chat $chat)
    {
        Gate::authorize('delete', $chat);

        $chat->delete();

        // Same rationale as archive() — sidebar already hid the row, no
        // need to pay for a redirect roundtrip on the AJAX path.
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('chats.index');
    }

    /**
     * Active, non-scribe personas offered for selection. Returns the persona's
     * default `ai_model_id` so the UI can suggest a default when the user
     * adds a custom pair.
     */
    private function speakerPersonas()
    {
        return Persona::query()
            ->where('is_active', true)
            ->where('is_scribe', false)
            ->where('is_chair', false)
            ->where('is_ephemeral', false)
            ->with('aiModel:id,name,model_string')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'title', 'avatar_emoji', 'avatar_color', 'description', 'communication_style', 'expertise_areas', 'ai_model_id']);
    }

    /**
     * Active AI models with a working provider — what the user can pick from
     * in custom_models / custom modes. Ordered by provider name then model
     * name so the GUI list stays predictable.
     */
    private function availableModels()
    {
        return AiModel::query()
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->whereNotNull('api_key'))
            ->with('provider:id,name,slug')
            ->get(['id', 'name', 'model_string', 'ai_provider_id', 'input_cost_per_1m_tokens', 'output_cost_per_1m_tokens'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'model_string' => $m->model_string,
                'provider' => $m->provider->name,
                'provider_slug' => $m->provider->slug,
                'in_cost' => (float) $m->input_cost_per_1m_tokens,
                'out_cost' => (float) $m->output_cost_per_1m_tokens,
            ])
            ->sortBy(['provider', 'name'])
            ->values();
    }
}
