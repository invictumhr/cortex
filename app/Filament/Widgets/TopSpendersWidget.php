<?php

namespace App\Filament\Widgets;

use App\Models\AiProvider;
use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Top 10 paying users by 30-day spend. Pure read-only — clicking a row could
 * later deep-link to the user's wallet detail, but for V1 we just show the
 * leaderboard.
 *
 * Rendered as a Blade widget (same reason as ProviderCostBreakdown — Filament
 * tables on GROUP BY trip MySQL strict mode's only_full_group_by).
 */
class TopSpendersWidget extends Widget
{
    protected static ?string $heading = 'Top spenders (30d)';

    protected string $view = 'filament.widgets.top-spenders';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $since = now()->subDays(30);

        // Aggregate first, then join users — avoids the only_full_group_by
        // trap entirely (no model query → no auto secondary sort).
        $rows = DB::table('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->where('wallet_transactions.type', WalletTransaction::TYPE_DEBIT)
            ->where('wallet_transactions.created_at', '>=', $since)
            ->selectRaw('
                users.id   AS user_id,
                users.name AS name,
                users.email AS email,
                COUNT(*) AS debit_count,
                SUM(wallet_transactions.provider_cost) AS provider_cost,
                SUM(wallet_transactions.user_cost) AS user_cost
            ')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('user_cost')
            ->limit(10)
            ->get();

        return [
            'heading' => self::$heading,
            'rows' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'email' => $r->email,
                'debit_count' => (int) $r->debit_count,
                'provider_cost' => (float) $r->provider_cost,
                'user_cost' => (float) $r->user_cost,
                'margin' => (float) $r->user_cost - (float) $r->provider_cost,
            ])->all(),
        ];
    }
}
