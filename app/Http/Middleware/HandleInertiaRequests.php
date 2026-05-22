<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $wallet = $user?->wallet;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            // Wallet state is shared on every Inertia response so the
            // composer, header, and banners always know whether the user
            // can afford another turn. Computed once per request — cheap
            // because `wallet` is already eager-loadable from the user.
            'wallet' => $wallet ? [
                'balance' => (float) $wallet->balance,
                'reserved' => (float) $wallet->reserved_balance,
                'available' => $wallet->availableBalance(),
                'currency' => $wallet->currency,
                // Thresholds the UI uses to decide when to warn vs block.
                'low_warning' => (float) config('cortex.billing.low_balance_warning', 0.50),
                'min_send' => (float) config('cortex.billing.min_send_balance', 0.05),
                'topup_url' => '/user/redeem-code',
            ] : null,
        ];
    }
}
