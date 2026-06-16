<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\TopupCodeService;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TopupRedeemTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Redeem User '.uniqid(),
            'email' => 'redeem'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);
    }

    private function fakeRequest(string $ip = '203.0.113.7'): Request
    {
        $request = Request::create('/user/redeem-code', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    public function test_generate_and_redeem_credits_the_wallet(): void
    {
        $svc = app(TopupCodeService::class);
        $user = $this->makeUser();

        $batch = $svc->generateBatch(2, 5.0, 'Test batch');
        $this->assertCount(2, $batch['codes']);
        $plaintext = $batch['codes'][0]['plaintext'];
        $this->assertMatchesRegularExpression('/^\d{14}$/', $plaintext);

        $code = $svc->redeem($user, $plaintext, $this->fakeRequest());

        $this->assertNotNull($code->redeemed_at);
        $this->assertSame($user->id, $code->redeemed_by_user_id);
        // 5€ deposit + 1€ first-deposit bonus (observer fires at ≥5€).
        $this->assertSame(6.0, (float) $user->wallet()->first()->balance);
    }

    public function test_redeem_accepts_dashed_input(): void
    {
        $svc = app(TopupCodeService::class);
        $user = $this->makeUser();

        $plaintext = $svc->generateBatch(1, 5.0)['codes'][0]['plaintext'];
        $dashed = implode('-', str_split($plaintext, 4));

        $svc->redeem($user, $dashed, $this->fakeRequest());

        // 5€ deposit + 1€ first-deposit bonus.
        $this->assertSame(6.0, (float) $user->wallet()->first()->balance);
    }

    public function test_same_user_retyping_own_code_is_idempotent(): void
    {
        $svc = app(TopupCodeService::class);
        $user = $this->makeUser();

        $plaintext = $svc->generateBatch(1, 5.0)['codes'][0]['plaintext'];

        $svc->redeem($user, $plaintext, $this->fakeRequest());
        $svc->redeem($user, $plaintext, $this->fakeRequest());

        // Balance includes the first-deposit bonus at most once; the core
        // assertion is that the 5€ deposit didn't double.
        $deposits = $user->wallet()->first()->transactions()->where('type', 'DEPOSIT')->count();
        $this->assertSame(1, $deposits, 'retyped code must not deposit twice');
    }

    public function test_other_users_code_is_rejected(): void
    {
        $svc = app(TopupCodeService::class);
        $owner = $this->makeUser();
        $thief = $this->makeUser();

        $plaintext = $svc->generateBatch(1, 5.0)['codes'][0]['plaintext'];
        $svc->redeem($owner, $plaintext, $this->fakeRequest());

        $this->expectException(\RuntimeException::class);
        $svc->redeem($thief, $plaintext, $this->fakeRequest('198.51.100.9'));
    }

    public function test_malformed_code_is_rejected_fast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('14 digits');

        app(TopupCodeService::class)->redeem($this->makeUser(), 'abc', $this->fakeRequest());
    }
}
