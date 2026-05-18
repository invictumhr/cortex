<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SystemController extends Controller
{
    /**
     * Global kill switch — instantly halts all running chat turns.
     */
    public function killSwitch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $key = (string) config('cortex.kill_switch_key');

        if ($validated['enabled']) {
            Cache::put($key, true, now()->addHours(6));
        } else {
            Cache::forget($key);
        }

        return response()->json(['ok' => true, 'kill_switch' => $validated['enabled']]);
    }
}
