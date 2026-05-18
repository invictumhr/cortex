<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Persona;
use App\Models\PowerShellPermission;
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
            'chats' => $request->user()->chats()
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'status', 'total_messages', 'total_cost', 'updated_at']),
            'personas' => $this->speakerPersonas(),
        ]);
    }

    public function show(Request $request, Chat $chat): Response
    {
        Gate::authorize('view', $chat);

        $chat->load(['personas' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('Chats/Show', [
            'chat' => $chat,
            'messages' => $chat->messages()
                ->with(['persona:id,name,title,avatar_emoji,avatar_color,is_scribe', 'attachments'])
                ->orderBy('id')
                ->get(),
            'scribeSummaries' => $chat->scribeSummaries()->orderBy('id')->get(),
            'allPersonas' => $this->speakerPersonas(),
            'chats' => $request->user()->chats()
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'status', 'updated_at']),
            'powershellEnabled' => (bool) PowerShellPermission::current()->is_enabled,
            'roundPresets' => config('cortex.round_presets'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rounds_per_turn' => ['nullable', 'integer', 'min:1', 'max:200'],
            'persona_ids' => ['array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
        ]);

        $chat = $request->user()->chats()->create([
            'title' => $validated['title'] ?: 'Novi boardroom',
            'description' => $validated['description'] ?? null,
            'rounds_per_turn' => $validated['rounds_per_turn'] ?? 1,
            'scribe_interval' => (int) config('cortex.default_scribe_interval', 50),
            'status' => Chat::STATUS_ACTIVE,
        ]);

        foreach ($validated['persona_ids'] ?? [] as $personaId) {
            $chat->personas()->attach($personaId, [
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        return redirect()->route('chats.show', $chat);
    }

    public function destroy(Chat $chat): RedirectResponse
    {
        Gate::authorize('delete', $chat);

        $chat->delete();

        return redirect()->route('chats.index');
    }

    /**
     * Active, non-scribe personas offered for selection.
     */
    private function speakerPersonas()
    {
        return Persona::query()
            ->where('is_active', true)
            ->where('is_scribe', false)
            ->with('aiModel:id,name,model_string')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'title', 'avatar_emoji', 'avatar_color', 'description', 'communication_style', 'expertise_areas', 'ai_model_id']);
    }
}
