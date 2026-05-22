<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the public REST API. Hashes the incoming token,
 * looks up the row, verifies it isn't revoked, checks the required scope,
 * enforces per-token rate limits (per-hour and per-day), and then logs the
 * user into the request context so downstream controllers see auth()->user().
 *
 * Usage:
 *   Route::post('/api/v1/discuss', …)
 *       ->middleware('api.token:cortex:discuss');
 *
 * The scope arg is optional — without it, any non-revoked token gets through
 * (and a scope-restricted token still needs to allow it via hasScope()).
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $bearer = $this->extractBearer($request);
        if ($bearer === null) {
            return response()->json(['error' => 'missing_bearer_token'], 401);
        }

        $token = ApiToken::where('token_hash', hash('sha256', $bearer))->first();
        if (! $token) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        if ($token->isRevoked()) {
            return response()->json(['error' => 'token_revoked'], 401);
        }

        if ($requiredScope !== null && ! $token->hasScope($requiredScope)) {
            return response()->json(['error' => 'scope_required', 'required' => $requiredScope], 403);
        }

        // Rate limits per token, separately for per-hour and per-day buckets.
        // The day bucket has a 24h TTL so a burst doesn't quietly carry over.
        $perHour = (int) config('cortex.api_rate_limit.per_hour', 10);
        $perDay = (int) config('cortex.api_rate_limit.per_day', 50);

        $hourKey = "apitoken:hour:{$token->id}";
        $dayKey = "apitoken:day:{$token->id}";

        if (RateLimiter::tooManyAttempts($hourKey, $perHour)) {
            return $this->limited($hourKey, $perHour, 'hour');
        }
        if (RateLimiter::tooManyAttempts($dayKey, $perDay)) {
            return $this->limited($dayKey, $perDay, 'day');
        }

        RateLimiter::hit($hourKey, 3600);
        RateLimiter::hit($dayKey, 86400);

        // Make $token available to downstream handlers (e.g. for stamping
        // chats with initiated_by_token_id) and authenticate the underlying
        // user so policies / auth()->user() work as usual.
        $request->attributes->set('api_token', $token);
        Auth::login($token->user);

        $token->update(['last_used_at' => now()]);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->bearerToken();
        if ($header === '') {
            return null;
        }

        // Accept tokens with our `ctx_` prefix or raw; the hash matches the
        // string we generated in ApiToken::issue (which includes the prefix).
        return $header;
    }

    private function limited(string $key, int $cap, string $window): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        return response()
            ->json([
                'error' => 'rate_limited',
                'window' => $window,
                'cap' => $cap,
                'retry_after_seconds' => $retryAfter,
            ], 429)
            ->header('Retry-After', (string) $retryAfter);
    }
}
