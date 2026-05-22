<?php

namespace App\Filament\Widgets;

use App\Models\TopupCode;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin top-up code KPIs: outstanding (un-redeemed) value, redeemed value,
 * conversion rate. Drives the SMS-PIN economics view.
 */
class TopupCodeStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $totalCount = TopupCode::query()->count();
        $totalValue = (float) TopupCode::query()->sum('amount');

        $redeemedCount = TopupCode::query()->whereNotNull('redeemed_at')->count();
        $redeemedValue = (float) TopupCode::query()->whereNotNull('redeemed_at')->sum('amount');

        $openCount = $totalCount - $redeemedCount;
        $openValue = $totalValue - $redeemedValue;

        $conversion = $totalCount > 0 ? round(($redeemedCount / $totalCount) * 100, 1) : 0.0;

        return [
            Stat::make('Codes issued', (string) number_format($totalCount))
                ->description('€'.number_format($totalValue, 2).' total face value')
                ->color('gray'),

            Stat::make('Outstanding', (string) number_format($openCount))
                ->description('€'.number_format($openValue, 2).' un-redeemed liability')
                ->color('warning'),

            Stat::make('Redeemed', (string) number_format($redeemedCount))
                ->description('€'.number_format($redeemedValue, 2).' converted to credit')
                ->color('success'),

            Stat::make('Conversion', $conversion.'%')
                ->description('Redeemed vs issued, by count')
                ->color($conversion >= 50 ? 'success' : 'info'),
        ];
    }
}
