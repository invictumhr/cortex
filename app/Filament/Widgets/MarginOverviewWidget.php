<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin headline: provider cost vs user billings — the gross margin in EUR
 * over the trailing 30 days, plus active-user count. One stat per number so
 * the dashboard is glanceable.
 */
class MarginOverviewWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $totals = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(provider_cost),0) AS provider_cost, COALESCE(SUM(user_cost),0) AS user_cost')
            ->first();

        $providerCost = (float) ($totals->provider_cost ?? 0);
        $userCost = (float) ($totals->user_cost ?? 0);
        $margin = $userCost - $providerCost;
        $marginPct = $userCost > 0 ? round(($margin / $userCost) * 100, 1) : 0.0;

        $payingUsers = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->where('created_at', '>=', $since)
            ->distinct('wallet_id')
            ->count('wallet_id');

        return [
            Stat::make('Provider cost (30d)', '€'.number_format($providerCost, 2))
                ->description('What the AI APIs cost us')
                ->color('warning'),

            Stat::make('User billings (30d)', '€'.number_format($userCost, 2))
                ->description('What we charged users')
                ->color('info'),

            Stat::make('Gross margin (30d)', '€'.number_format($margin, 2))
                ->description($marginPct.'% of user billings')
                ->color($margin > 0 ? 'success' : 'danger'),

            Stat::make('Paying users (30d)', (string) $payingUsers)
                ->description('Distinct wallets with at least one deposit')
                ->color('primary'),
        ];
    }
}
