<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChatPersonaController extends Controller
{
    /**
     * Add a persona to a chat or toggle its active (speaking) state.
     * Idempotent: attaches the persona if it is not yet in the chat.
     */
    public function update(Request $request, Chat $chat, Persona $persona): JsonResponse
    {
        Gate::authorize('update', $chat);

        $active = $request->boolean('active', true);

        $exists = $chat->personas()->where('personas.id', $persona->id)->exists();

        if ($exists) {
            $chat->personas()->updateExistingPivot($persona->id, [
                'is_active' => $active,
                'left_at' => $active ? null : now(),
            ]);
        } else {
            $chat->personas()->attach($persona->id, [
                'is_active' => $active,
                'joined_at' => now(),
            ]);
        }

        $chat->load(['personas' => fn ($q) => $q->orderBy('sort_order')]);

        return response()->json([
            'ok' => true,
            'personas' => $chat->personas,
        ]);
    }
}
