<?php

namespace Tests\Feature\Billing;

use App\Listeners\GrantSignupCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeCreditTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Credit User '.uniqid(),
            'email' => 'credit'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ], $attributes));
    }

    public function test_verified_event_grants_signup_credit_once(): void
    {
        $user = $this->makeUser();
        $listener = app(GrantSignupCredit::class);

        $listener->handle(new Verified($user));
        $listener->handle(new Verified($user->fresh())); // event redelivery

        $this->assertSame(0.50, (float) $user->wallet()->first()->balance);
        $this->assertNotNull($user->fresh()->free_credit_granted_at);
    }

    public function test_ip_farming_burst_is_denied_but_stamped(): void
    {
        $ipHash = hash('sha256', '203.0.113.55');

        foreach (range(1, 3) as $i) {
            $this->makeUser(['registration_ip_hash' => $ipHash]);
        }
        $farmer = $this->makeUser(['registration_ip_hash' => $ipHash]);

        app(GrantSignupCredit::class)->handle(new Verified($farmer));

        $this->assertSame(0.0, (float) $farmer->wallet()->first()->balance, 'farmed signup gets no credit');
        $this->assertNotNull($farmer->fresh()->free_credit_granted_at, 'denial is stamped so we never reconsider');
    }

    public function test_first_deposit_of_5_eur_or_more_triggers_bonus_once(): void
    {
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);

        $svc->deposit($wallet, 5.0, 'topup_code:test-1');

        $bonus = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_GRANT)
            ->get()
            ->filter(fn ($tx) => ($tx->metadata['reason'] ?? '') === 'first_deposit_bonus');
        $this->assertCount(1, $bonus, 'first qualifying deposit grants the bonus');

        $svc->deposit($wallet->fresh(), 10.0, 'topup_code:test-2');

        $bonusAfterSecond = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_GRANT)
            ->get()
            ->filter(fn ($tx) => ($tx->metadata['reason'] ?? '') === 'first_deposit_bonus');
        $this->assertCount(1, $bonusAfterSecond, 'second deposit must not re-trigger the bonus');

        // 5 + 10 deposits + 1 bonus
        $this->assertSame(16.0, (float) $wallet->fresh()->balance);
    }

    public function test_small_first_deposit_gets_no_bonus(): void
    {
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);

        $svc->deposit($wallet, 4.99, 'topup_code:small-1');

        $this->assertSame(4.99, (float) $wallet->fresh()->balance, 'below-threshold deposit credits only itself');
    }
}
