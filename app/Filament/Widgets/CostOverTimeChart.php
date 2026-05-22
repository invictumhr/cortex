<?php

namespace App\Filament\Widgets;

use App\Models\WalletTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * 30-day line chart of daily provider cost vs user billings.
 * Same data as MarginOverviewWidget but unrolled over time so admin can
 * spot trends — sudden spend spikes, slow days, holiday dips.
 */
class CostOverTimeChart extends ChartWidget
{
    protected ?string $heading = 'Spend over time (30d)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $since = now()->startOfDay()->subDays(29);

        // GROUP BY DATE() lets one query carry 30 days × 2 metrics; no
        // application-side bucketing. Use raw cast for MySQL compatibility.
        $rows = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                DATE(created_at) AS d,
                COALESCE(SUM(provider_cost), 0) AS provider_cost,
                COALESCE(SUM(user_cost), 0) AS user_cost
            ')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        // Fill the missing days with zero so the line is continuous rather
        // than gappy.
        $labels = [];
        $providerSeries = [];
        $userSeries = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $labels[] = substr($day, 5); // MM-DD on the x-axis
            $providerSeries[] = round((float) ($rows[$day]->provider_cost ?? 0), 4);
            $userSeries[] = round((float) ($rows[$day]->user_cost ?? 0), 4);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Provider cost (€)',
                    'data' => $providerSeries,
                    'borderColor' => '#d97706',
                    'backgroundColor' => 'rgba(217, 119, 6, 0.1)',
                ],
                [
                    'label' => 'User billings (€)',
                    'data' => $userSeries,
                    'borderColor' => '#0284c7',
                    'backgroundColor' => 'rgba(2, 132, 199, 0.1)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
