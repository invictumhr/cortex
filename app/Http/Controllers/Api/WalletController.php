<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\LogsApiUsage;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\ApiTokenUsage;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only wallet endpoints. There is no API path that mutates the wallet
 * directly — top-ups go through PIN redemption in the user panel, debits
 * happen via boardroom runs. This controller just exposes the current state
 * and recent ledger so external clients can budget around it.
 */
class WalletController extends Controller
{
    use LogsApiUsage;

    public function __construct(private WalletService $wallets) {}

    /**
     * GET /api/v1/wallet
     *
     * Snapshot of the caller's wallet: balance, reserved, available, 30-day
     * spend, and which margin tier they're on right now. The wallet is lazily
     * created on first hit so token holders never see a 404 here.
     */
    public function show(Request $request): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);
        $user = $token->user;

        $wallet = $this->wallets->forUser($user);

        // Trailing 30-day spend drives the margin tier and is a useful metric
        // for clients showing "you've spent €X this month" panels.
        $spend30d = (float) WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_DEBIT)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('user_cost');

        $margin = $this->wallets->marginMultiplierFor($user);
        $enterpriseThreshold = (float) config('cortex.billing.enterprise_threshold_eur', 100.0);

        return $this->logUsage($token, 'GET /api/v1/wallet', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'balance' => round((float) $wallet->balance, 6),
                'reserved' => round((float) $wallet->reserved_balance, 6),
                'available' => round($wallet->availableBalance(), 6),
                'currency' => $wallet->currency,
                'spend_30d' => round($spend30d, 6),
                'margin_multiplier' => $margin,
                'tier' => $spend30d >= $enterpriseThreshold ? 'enterprise' : 'hobby',
                'low_warning_threshold' => (float) config('cortex.billing.low_balance_warning', 0.50),
                'min_send_balance' => (float) config('cortex.billing.min_send_balance', 0.05),
                'topup_url' => url('/user/redeem-code'),
            ]),
        );
    }

    /**
     * GET /api/v1/wallet/transactions
     *
     * Page through the ledger. Default page size is 50, max 200. `?type=DEBIT`
     * filters by transaction kind so clients can pull just spend rows.
     */
    public function transactions(Request $request): JsonResponse
    {
        $started = microtime(true);
        $token = $this->token($request);
        $user = $token->user;

        $validated = $this->validateLogged($request, [
            'before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'string', 'in:DEPOSIT,RESERVE,RELEASE,DEBIT,REFUND,GRANT,ADJUSTMENT'],
        ], $token, 'GET /api/v1/wallet/transactions', $started);

        $wallet = $this->wallets->forUser($user);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('created_at');

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['before'])) {
            $query->where('created_at', '<', $validated['before']);
        }

        // One extra row tells us whether another page exists.
        $rows = $query->limit($limit + 1)->get([
            'id', 'type', 'amount', 'reserved_delta',
            'source_type', 'source_id',
            'provider_cost', 'user_cost', 'metadata',
            'created_at',
        ]);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return $this->logUsage($token, 'GET /api/v1/wallet/transactions', ApiTokenUsage::STATUS_OK, $started,
            response()->json([
                'transactions' => $rows->values(),
                'has_more' => $hasMore,
                'next_before' => $hasMore ? $rows->last()?->created_at?->toIso8601String() : null,
            ]),
        );
    }

    private function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('api_token');
        \assert($token instanceof ApiToken);

        return $token;
    }
}
