<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paddle webhook receiver. Verifies the signature with the configured secret,
 * resolves the user via `custom_data.user_id` (set when we create the
 * checkout), maps the priced transaction to a deposit amount, and calls
 * WalletService::deposit() — which is idempotent by `payment_source_ref` so
 * Paddle's at-least-once delivery never double-credits.
 *
 * Paddle Billing v2 webhook format:
 *   POST  /paddle/webhook
 *   header  Paddle-Signature: ts=…;h1=…
 *   body    {"event_type":"transaction.completed", "data":{...}}
 *
 * Doc: https://developer.paddle.com/webhooks/signature-verification
 */
class PaddleWebhookController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureValid($request)) {
            Log::warning('[paddle] webhook signature invalid', [
                'ip' => $request->ip(),
                'event' => $request->input('event_type'),
            ]);

            return response()->json(['error' => 'signature_invalid'], 400);
        }

        $eventType = (string) $request->input('event_type');
        $data = (array) $request->input('data', []);

        if ($eventType !== 'transaction.completed') {
            // Other lifecycle events (transaction.created, subscription.*,
            // etc.) we ack but don't act on for the prepaid-only V1.
            return response()->json(['ignored' => $eventType]);
        }

        try {
            $this->handleTransactionCompleted($data);
        } catch (Throwable $e) {
            Log::error('[paddle] webhook handler failed', [
                'event' => $eventType,
                'tx_id' => $data['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            // 5xx makes Paddle retry — preferable to silently swallowing.
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verify HMAC-SHA256 signature per Paddle's spec. Header format is
     * `ts=<unix>;h1=<hex>`. We sign `<ts>:<raw_body>` with the shared secret.
     */
    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('cortex.paddle.webhook_secret');
        if ($secret === '') {
            // Misconfigured — be loud, fail closed.
            Log::error('[paddle] webhook secret not configured');

            return false;
        }

        $header = (string) $request->header('Paddle-Signature', '');
        $parts = [];
        foreach (explode(';', $header) as $segment) {
            [$k, $v] = array_pad(explode('=', $segment, 2), 2, null);
            if ($k && $v) {
                $parts[$k] = $v;
            }
        }

        $ts = $parts['ts'] ?? null;
        $h1 = $parts['h1'] ?? null;
        if (! $ts || ! $h1) {
            return false;
        }

        // Reject replays older than 5 min.
        if (abs(time() - (int) $ts) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.':'.$request->getContent(), $secret);

        return hash_equals($expected, $h1);
    }

    /**
     * Resolve the user, compute the deposit amount from the priced line items,
     * and apply the deposit. WalletService is idempotent on payment_source_ref
     * so duplicate webhook deliveries are safe.
     */
    private function handleTransactionCompleted(array $data): void
    {
        $paddleTxId = (string) ($data['id'] ?? '');
        if ($paddleTxId === '') {
            throw new \RuntimeException('Paddle transaction missing id');
        }

        $userId = $this->resolveUserId($data);
        $user = User::find($userId);
        if (! $user) {
            throw new \RuntimeException("Paddle transaction $paddleTxId references unknown user id=$userId");
        }

        $eurAmount = $this->resolveDepositAmount($data);
        if ($eurAmount <= 0) {
            throw new \RuntimeException("Paddle transaction $paddleTxId resolved to non-positive amount");
        }

        $wallet = $this->walletService->forUser($user);

        $this->walletService->deposit(
            $wallet,
            $eurAmount,
            paymentSourceRef: $paddleTxId,
            metadata: [
                'paddle' => [
                    'tx_id' => $paddleTxId,
                    'customer_id' => $data['customer_id'] ?? null,
                    'currency' => $data['currency_code'] ?? 'EUR',
                    'origin' => $data['origin'] ?? null,
                ],
            ],
        );
    }

    /**
     * Pull user_id out of the transaction. We pass it as custom_data when
     * creating the checkout — the user is logged in at that point.
     */
    private function resolveUserId(array $data): int
    {
        $custom = (array) ($data['custom_data'] ?? []);
        $userId = (int) ($custom['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new \RuntimeException('Paddle transaction missing custom_data.user_id');
        }

        return $userId;
    }

    /**
     * Walk the line items, match each Paddle price_id to our top-up tiers,
     * and sum the EUR amounts. Keeps us insulated from currency conversion
     * Paddle would otherwise hand us in random subunits.
     */
    private function resolveDepositAmount(array $data): float
    {
        $tiers = (array) config('cortex.paddle.topup_tiers', []);
        $priceToAmount = [];
        foreach ($tiers as $tier) {
            if (! empty($tier['price_id'])) {
                $priceToAmount[$tier['price_id']] = (float) $tier['amount'];
            }
        }

        $total = 0.0;
        foreach ((array) ($data['items'] ?? []) as $item) {
            $priceId = $item['price']['id'] ?? null;
            $qty = (int) ($item['quantity'] ?? 1);
            if ($priceId && isset($priceToAmount[$priceId])) {
                $total += $priceToAmount[$priceId] * $qty;
            }
        }

        return round($total, 6);
    }
}
