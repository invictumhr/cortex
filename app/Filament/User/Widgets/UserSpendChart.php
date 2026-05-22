<?php

namespace App\Filament\User\Widgets;

use App\Models\WalletTransaction;
use Filament\Widgets\ChartWidget;

/**
 * User's own daily spend over the last 30 days. Mirrors the admin chart but
 * scoped to the authenticated user's wallet.
 */
class UserSpendChart extends ChartWidget
{
    protected ?string $heading = 'My spend (30d)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $user = auth()->user();
        if (! $user || ! $user->wallet) {
            return ['datasets' => [], 'labels' => []];
        }

        $since = now()->startOfDay()->subDays(29);
        $walletId = $user->wallet->id;

        $rows = WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) AS d, COALESCE(SUM(user_cost), 0) AS spent')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $labels = [];
        $data = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $labels[] = substr($day, 5);
            $data[] = round((float) ($rows[$day]->spent ?? 0), 4);
        }

        return [
            'datasets' => [[
                'label' => 'Daily spend (€)',
                'data' => $data,
                'borderColor' => '#0284c7',
                'backgroundColor' => 'rgba(2, 132, 199, 0.15)',
                'fill' => true,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
