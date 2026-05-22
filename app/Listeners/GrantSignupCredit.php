<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Billing\WalletService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;

/**
 * Hand new users the configured signup free credit (Opcija B: €0.50 immediate,
 * €1 bonus after first €5+ deposit — this listener handles the immediate half).
 *
 * Triggers on email verification, not on registration: that way a throwaway
 * signup that never clicks the verification link costs us €0. IP/UA
 * fingerprints captured at registration time are reused here for the
 * anti-farming check.
 */
class GrantSignupCredit
{
    public function __construct(private WalletService $walletService) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $wallet = $this->walletService->forUser($user);

        // Idempotency — never grant twice even if the event fires twice.
        if ($user->free_credit_granted_at !== null) {
            return;
        }

        if ($this->looksLikeFarming($user)) {
            Log::warning('[signup-credit] denied — fingerprint matches recent burst', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip_hash' => substr((string) $user->registration_ip_hash, 0, 12).'…',
            ]);
            // Still stamp the user so we don't reconsider — explicit denial,
            // not a forgotten state.
            $user->update(['free_credit_granted_at' => now()]);

            return;
        }

        $amount = (float) config('cortex.billing.free_credit_signup', 0.50);
        if ($amount <= 0) {
            return;
        }

        $this->walletService->grant(
            $wallet,
            $amount,
            'signup bonus',
            sourceType: 'signup',
            sourceId: $user->id,
        );

        $user->update(['free_credit_granted_at' => now()]);
    }

    /**
     * Three or more registrations from the same IP hash in the trailing 7 days
     * is enough signal to refuse the grant. Tunable later via config if it
     * starts blocking legitimate co-workers behind one NAT.
     */
    private function looksLikeFarming(User $user): bool
    {
        if (blank($user->registration_ip_hash)) {
            return false;
        }

        $recentSame = User::query()
            ->where('registration_ip_hash', $user->registration_ip_hash)
            ->where('id', '!=', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return $recentSame >= 3;
    }
}
