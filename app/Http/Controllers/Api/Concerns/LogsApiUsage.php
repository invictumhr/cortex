<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use Illuminate\Http\JsonResponse;

/**
 * Append-only audit log helper for the REST API. Every endpoint that lives
 * under `api.token` middleware calls `logUsage()` exactly once per request
 * so the user dashboard ("Token X made N calls, cost €Y today") stays honest.
 *
 * The pattern mirrors DiscussController::logAndReturn(): start a timer at
 * the top of the controller, then wrap the eventual response with
 * `$this->logUsage(...)` which writes the row and passes the response through.
 */
trait LogsApiUsage
{
    /**
     * Write one api_token_usages row and return the response untouched.
     * Caller controls status (ok / error / insufficient_funds / rate_limited)
     * and the cost split. response_time_ms is computed from $startedAt.
     */
    protected function logUsage(
        ApiToken $token,
        string $endpoint,
        string $status,
        float $startedAt,
        JsonResponse $response,
        ?int $chatId = null,
        ?int $chatMessageId = null,
        float $providerCost = 0,
        float $userCost = 0,
    ): JsonResponse {
        ApiTokenUsage::create([
            'api_token_id' => $token->id,
            'chat_id' => $chatId,
            'chat_message_id' => $chatMessageId,
            'endpoint' => $endpoint,
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'response_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'status' => $status,
            'created_at' => now(),
        ]);

        return $response;
    }
}
