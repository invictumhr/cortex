<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\Chat\ChatOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChatActionController extends Controller
{
    public function __construct(private ChatOrchestrator $orchestrator) {}

    public function pause(Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);
        $this->orchestrator->pause($chat);

        return response()->json(['ok' => true, 'status' => $chat->fresh()->status]);
    }

    public function resume(Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);
        $this->orchestrator->resume($chat);

        return response()->json(['ok' => true, 'status' => $chat->fresh()->status]);
    }

    public function setRounds(Request $request, Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'rounds_per_turn' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $chat->update(['rounds_per_turn' => $validated['rounds_per_turn']]);

        return response()->json(['ok' => true, 'rounds_per_turn' => $chat->rounds_per_turn]);
    }

    public function addRounds(Request $request, Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);

        $validated = $request->validate([
            'rounds' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $this->orchestrator->addRounds($chat, $validated['rounds']);

        return response()->json(['ok' => true, 'rounds_per_turn' => $chat->fresh()->rounds_per_turn]);
    }
}
