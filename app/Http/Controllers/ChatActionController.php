<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\Chat\ChatOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ChatActionController extends Controller
{
    /** Seconds a heartbeat keeps a continuous discussion alive. */
    private const HEARTBEAT_TTL = 50;

    public function __construct(private ChatOrchestrator $orchestrator) {}

    public function pause(Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);
        $this->orchestrator->pause($chat, conclude: true);

        return response()->json(['ok' => true, 'status' => $chat->fresh()->status]);
    }

    public function resume(Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);
        $this->touchHeartbeat($chat);
        $this->orchestrator->resume($chat);

        return response()->json(['ok' => true, 'status' => $chat->fresh()->status]);
    }

    /**
     * The open chat page pings this; the discussion loop stops itself once
     * the heartbeat lapses (page closed or navigated away).
     */
    public function heartbeat(Chat $chat): JsonResponse
    {
        Gate::authorize('view', $chat);
        $this->touchHeartbeat($chat);

        return response()->json(['ok' => true]);
    }

    /**
     * Fired via sendBeacon when the user leaves the page — stop the
     * discussion silently, with no Chair verdict.
     */
    public function leave(Chat $chat): JsonResponse
    {
        Gate::authorize('update', $chat);
        Cache::forget('cortex:heartbeat:'.$chat->id);
        $this->orchestrator->pause($chat);

        return response()->json(['ok' => true]);
    }

    private function touchHeartbeat(Chat $chat): void
    {
        Cache::put('cortex:heartbeat:'.$chat->id, true, self::HEARTBEAT_TTL);
    }
}
