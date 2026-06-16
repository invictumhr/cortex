<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Optional `Idempotency-Key` header support for billable POST endpoints.
 *
 * Network timeouts make API clients retry — without this, every retry of
 * POST /discuss spawns (and bills) a brand-new boardroom. A client that sends
 * the same Idempotency-Key gets the original response replayed instead.
 *
 * Keys are scoped per token, live 24h, and are claimed atomically via
 * Cache::add() so two concurrent retries can't both run the work. Failed
 * (non-2xx) attempts release the claim so the client may retry cleanly.
 */
trait HandlesIdempotency
{
    private const IDEMPOTENCY_TTL_HOURS = 24;

    /** Cache key for this (token, Idempotency-Key) pair, or null when absent. */
    protected function idempotencyKey(Request $request, ApiToken $token): ?string
    {
        $raw = trim((string) $request->header('Idempotency-Key', ''));

        if ($raw === '' || strlen($raw) > 255) {
            return null;
        }

        return 'api:idem:'.$token->id.':'.hash('sha256', $raw);
    }

    /**
     * Claim the key or replay the stored outcome. Returns null when the caller
     * owns the claim and should execute the request; returns a response when
     * the work already happened (replay) or is still in flight (409).
     */
    protected function beginIdempotent(?string $cacheKey): ?JsonResponse
    {
        if ($cacheKey === null) {
            return null;
        }

        if (Cache::add($cacheKey, 'pending', now()->addHours(self::IDEMPOTENCY_TTL_HOURS))) {
            return null; // We own the claim.
        }

        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return response()
                ->json($stored['body'], $stored['status'])
                ->header('Idempotency-Replayed', 'true');
        }

        // Claimed but not yet finished — the original request is in flight.
        return response()->json([
            'error' => 'idempotency_conflict',
            'message' => 'A request with this Idempotency-Key is still being processed.',
        ], 409);
    }

    /**
     * Record the outcome. Successful responses are stored for replay; failures
     * release the claim so the client can retry with the same key.
     */
    protected function finishIdempotent(?string $cacheKey, JsonResponse $response): JsonResponse
    {
        if ($cacheKey === null) {
            return $response;
        }

        if ($response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => $response->getData(true),
            ], now()->addHours(self::IDEMPOTENCY_TTL_HOURS));
        } else {
            Cache::forget($cacheKey);
        }

        return $response;
    }
}
