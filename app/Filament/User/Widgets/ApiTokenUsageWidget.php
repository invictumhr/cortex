<?php

namespace App\Filament\User\Widgets;

use App\Models\ApiTokenUsage;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Per-token usage roll-up for the trailing 30 days — call count and EUR spend
 * grouped by API token, so a user can see which integration is eating their
 * credits.
 */
class ApiTokenUsageWidget extends Widget
{
    protected static ?string $heading = 'API token usage (30d)';

    protected string $view = 'filament.user.widgets.api-token-usage';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $user = auth()->user();
        if (! $user) {
            return ['heading' => self::$heading, 'rows' => []];
        }

        $since = now()->subDays(30);

        $rows = DB::table('api_token_usages')
            ->join('api_tokens', 'api_tokens.id', '=', 'api_token_usages.api_token_id')
            ->where('api_tokens.user_id', $user->id)
            ->where('api_token_usages.created_at', '>=', $since)
            ->selectRaw('
                api_tokens.id     AS token_id,
                api_tokens.label  AS label,
                api_tokens.revoked_at AS revoked_at,
                COUNT(*) AS call_count,
                SUM(CASE WHEN api_token_usages.status = "ok" THEN 1 ELSE 0 END) AS ok_count,
                SUM(api_token_usages.user_cost) AS user_cost,
                MAX(api_token_usages.created_at) AS last_call_at
            ')
            ->groupBy('api_tokens.id', 'api_tokens.label', 'api_tokens.revoked_at')
            ->orderByDesc('user_cost')
            ->get();

        return [
            'heading' => self::$heading,
            'rows' => $rows->map(fn ($r) => [
                'label' => $r->label,
                'revoked' => $r->revoked_at !== null,
                'call_count' => (int) $r->call_count,
                'ok_count' => (int) $r->ok_count,
                'user_cost' => (float) $r->user_cost,
                'last_call_at' => $r->last_call_at,
            ])->all(),
        ];
    }
}
