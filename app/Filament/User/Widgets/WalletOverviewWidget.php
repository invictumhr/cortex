<?php

namespace App\Filament\User\Widgets;

use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers a user wants to glance at on the dashboard: spendable
 * balance, what's currently held for in-flight runs, and 30-day spend.
 *
 * Polls every 30s. We deliberately don't cache — a stale balance becomes a
 * promise we can't keep when the next reserve runs.
 */
class WalletOverviewWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $wallet = app(WalletService::class)->forUser($user);
        $wallet->refresh();

        $available = $wallet->availableBalance();
        $reserved = (float) $wallet->reserved_balance;

        $spend30d = (float) WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('user_cost');

        return [
            Stat::make('Spendable balance', '€'.number_format($available, 2))
                ->description($available > 0.01 ? 'Ready to use' : 'Top up to start a discussion')
                ->color($available > 0.50 ? 'success' : ($available > 0.05 ? 'warning' : 'danger')),

            Stat::make('Reserved (in-flight)', '€'.number_format($reserved, 2))
                ->description($reserved > 0 ? 'Held for active boardroom runs' : 'No active runs')
                ->color('gray'),

            Stat::make('30-day spend', '€'.number_format($spend30d, 2))
                ->description('Across all boardroom messages and synthesis')
                ->color('primary'),
        ];
    }
}
