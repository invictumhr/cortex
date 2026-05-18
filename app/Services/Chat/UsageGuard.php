<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Platform-wide abuse / runaway-cost protection, checked before each new turn.
 */
class UsageGuard
{
    public function check(): void
    {
        $spentToday = (float) ChatMessage::query()
            ->whereDate('created_at', today())
            ->sum('cost');

        $dailyLimit = (float) config('cortex.daily_budget_limit', 25.0);

        if ($spentToday >= $dailyLimit) {
            throw new RuntimeException(
                'Dnevni budžet (€'.number_format($dailyLimit, 2).') je dosegnut — današnja potrošnja je €'.number_format($spentToday, 4).'.'
            );
        }

        $perMinute = max(1, (int) config('cortex.max_turns_per_minute', 20));

        if (RateLimiter::tooManyAttempts('cortex:turns', $perMinute)) {
            throw new RuntimeException(
                'Previše rasprava pokrenuto u kratko vrijeme — pričekaj '.RateLimiter::availableIn('cortex:turns').' s.'
            );
        }

        RateLimiter::hit('cortex:turns', 60);
    }
}
