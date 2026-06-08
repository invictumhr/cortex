<?php

namespace App\Filament\Widgets;

use App\Models\AiProvider;
use App\Models\WalletTransaction;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Provider × model breakdown of the trailing 30-day spend, so admin can spot
 * which provider is eating margin or being abused. Rendered as a plain Blade
 * table — a Filament TableWidget needs an Eloquent builder, and any Eloquent
 * query with GROUP BY trips MySQL's only_full_group_by because Filament adds
 * a secondary `id DESC` sort for stable pagination.
 */
class ProviderCostBreakdownWidget extends Widget
{
    protected static ?string $heading = 'Provider × model spend (30d)';

    protected string $view = 'filament.widgets.provider-cost-breakdown';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $since = now()->subDays(30);

        $rows = DB::table('wallet_transactions')
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                provider_id,
                COUNT(*) AS call_count,
                SUM(provider_cost) AS provider_cost,
                SUM(user_cost) AS user_cost
            ')
            ->groupBy('provider_id')
            ->orderByDesc('user_cost')
            ->get();

        $providers = AiProvider::all()->keyBy('id');

        return [
            'heading' => self::$heading,
            'rows' => $rows->map(fn ($r) => [
                'provider' => $providers[$r->provider_id]->name ?? 'Unknown',
                'dashboard_url' => $providers[$r->provider_id]->billing_dashboard_url ?? null,
                'call_count' => (int) $r->call_count,
                'provider_cost' => (float) $r->provider_cost,
                'user_cost' => (float) $r->user_cost,
                'margin' => (float) $r->user_cost - (float) $r->provider_cost,
            ])->all(),
            'all_providers' => $providers->map(fn ($p) => [
                'name' => $p->name,
                'dashboard_url' => $p->billing_dashboard_url,
                'is_active' => $p->is_active,
            ])->values()->all(),
        ];
    }
}
