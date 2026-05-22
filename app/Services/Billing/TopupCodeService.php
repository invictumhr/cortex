<?php

namespace App\Services\Billing;

use App\Models\TopupCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Pre-paid top-up codes — issue and redeem.
 *
 * Codes are 14-digit numeric strings delivered to users out-of-band (SMS,
 * voucher card, etc). Stored hashed; only the issue() return value ever
 * carries the plaintext, so the admin must capture it at generation.
 *
 * Redemption is rate-limited per IP and per user to make a 14-digit PIN
 * un-bruteforcable in practice (1e14 keyspace, but we cap attempts well
 * below that).
 */
class TopupCodeService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Generate a batch of N codes, all worth the same amount. Returns the
     * plaintext PINs alongside their saved models so the caller can hand
     * them out (SMS, print, email — whatever).
     *
     * @return array<int, array{model: TopupCode, plaintext: string}>
     */
    public function generateBatch(
        int $count,
        float $eachAmount,
        ?string $batchLabel = null,
        ?User $createdBy = null,
        array $metadata = [],
    ): array {
        if ($count < 1 || $count > 10000) {
            throw new \InvalidArgumentException('Batch size must be 1..10000.');
        }
        if ($eachAmount <= 0) {
            throw new \InvalidArgumentException('Code amount must be positive.');
        }

        $issued = [];
        DB::transaction(function () use ($count, $eachAmount, $batchLabel, $createdBy, $metadata, &$issued) {
            for ($i = 0; $i < $count; $i++) {
                // Retry on (statistically impossible) hash collision so the
                // batch always produces the requested count.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $plaintext = $this->randomFourteenDigitPin();
                    $hash = hash('sha256', $plaintext);
                    if (! TopupCode::where('code_hash', $hash)->exists()) {
                        $model = TopupCode::create([
                            'code_hash' => $hash,
                            'amount' => $eachAmount,
                            'batch_label' => $batchLabel,
                            'metadata' => $metadata,
                            'created_by_user_id' => $createdBy?->id,
                        ]);
                        $issued[] = ['model' => $model, 'plaintext' => $plaintext];
                        break;
                    }
                }
            }
        });

        return $issued;
    }

    /**
     * Redeem a plaintext code for the given user. On success deposits the
     * code's euro amount into the user's wallet (idempotent by code hash —
     * any retry returns the same wallet transaction) and marks the code
     * consumed.
     *
     * Throws RuntimeException on invalid / already-redeemed / rate-limited.
     */
    public function redeem(User $user, string $rawCode, Request $request): TopupCode
    {
        $clean = $this->normaliseInput($rawCode);
        if ($clean === null) {
            throw new RuntimeException('Code must be 14 digits.');
        }

        // Brute-force defence: per-IP and per-user caps.
        $ipKey = 'topup:ip:'.sha1((string) $request->ip());
        $userKey = 'topup:user:'.$user->id;
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            throw new RuntimeException('Too many attempts. Try again in '.RateLimiter::availableIn($ipKey).'s.');
        }
        if (RateLimiter::tooManyAttempts($userKey, 20)) {
            throw new RuntimeException('Too many redemption attempts on your account.');
        }
        RateLimiter::hit($ipKey, 600);   // 10 / 10min per IP
        RateLimiter::hit($userKey, 3600); // 20 / hour per user

        $hash = hash('sha256', $clean);

        // The whole match → mark → deposit chain runs in a single
        // transaction so two parallel redemptions of the same PIN can't
        // both credit. The first commits, the second sees redeemed_at !== null.
        return DB::transaction(function () use ($user, $hash, $request) {
            $code = TopupCode::where('code_hash', $hash)->lockForUpdate()->first();

            if (! $code) {
                throw new RuntimeException('Invalid code.');
            }
            if ($code->isRedeemed()) {
                // Same user re-typing their own code: report the original
                // success quietly rather than crying foul (idempotent UX).
                if ($code->redeemed_by_user_id === $user->id) {
                    return $code;
                }
                throw new RuntimeException('Code has already been used.');
            }

            $wallet = $this->walletService->forUser($user);

            // payment_source_ref is the code id — keeps the WalletService
            // deposit() path idempotent on retries.
            $deposit = $this->walletService->deposit(
                $wallet,
                (float) $code->amount,
                paymentSourceRef: 'topup_code:'.$code->id,
                metadata: [
                    'topup_code_id' => $code->id,
                    'batch_label' => $code->batch_label,
                ],
            );

            $code->update([
                'redeemed_at' => now(),
                'redeemed_by_user_id' => $user->id,
                'redeemed_ip_hash' => hash('sha256', (string) $request->ip()),
                'wallet_transaction_id' => $deposit->id,
            ]);

            // Clear the rate-limit bucket on success so a legit user with a
            // typo on their first attempt isn't punished for the next code.
            RateLimiter::clear('topup:ip:'.sha1((string) $request->ip()));

            return $code->fresh();
        });
    }

    /**
     * Accept "1234-5678-9012-34", "1234 5678 9012 34", or raw 14 digits.
     * Strip everything non-numeric and validate length.
     */
    private function normaliseInput(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return strlen((string) $digits) === 14 ? (string) $digits : null;
    }

    /**
     * 14 cryptographically-random digits. Using random_int rather than mt_rand
     * for the entropy floor — a leaked predictable seed would otherwise let
     * someone enumerate the keyspace.
     */
    private function randomFourteenDigitPin(): string
    {
        $out = '';
        for ($i = 0; $i < 14; $i++) {
            $out .= (string) random_int(0, 9);
        }

        return $out;
    }

    /**
     * Convenience — pretty-print a stored code's plaintext (post-issue, when
     * we still hold it) as 4-4-4-2 groups: 1234-5678-9012-34.
     */
    public static function formatForDisplay(string $plaintext): string
    {
        if (strlen($plaintext) !== 14) {
            return $plaintext;
        }

        return implode('-', [
            substr($plaintext, 0, 4),
            substr($plaintext, 4, 4),
            substr($plaintext, 8, 4),
            substr($plaintext, 12, 2),
        ]);
    }
}
