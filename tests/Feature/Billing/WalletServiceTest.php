<?php

namespace Tests\Feature\Billing;

use App\Exceptions\InsufficientFundsException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Test User '.uniqid(),
            'email' => 'test'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);
    }

    private function makeModel(): AiModel
    {
        $provider = AiProvider::create([
            'slug' => 'test-prov-'.uniqid(),
            'name' => 'Test Provider',
            'api_key' => 'key',
            'is_active' => true,
        ]);

        return AiModel::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Test Model '.uniqid(),
            'model_string' => 'test-model-'.uniqid(),
            'input_cost_per_1m_tokens' => 3.0,
            'output_cost_per_1m_tokens' => 15.0,
            'is_active' => true,
        ]);
    }

    public function test_reserve_moves_balance_into_reserved_and_release_undoes_it(): void
    {
        // Spendable (balance) drops by the reserved amount, reserved goes up
        // by the same. Total funds (balance+reserved) stay constant.
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $svc->grant($wallet, 10.0, 'test seed');
        $wallet->refresh();
        $this->assertSame(10.0, (float) $wallet->balance);

        $reserve = $svc->reserve($wallet, 3.5, $this->makeModel());
        $wallet->refresh();
        $this->assertSame(6.5, (float) $wallet->balance, 'balance drops by reserved');
        $this->assertSame(3.5, (float) $wallet->reserved_balance);
        $this->assertSame(10.0, (float) $wallet->balance + (float) $wallet->reserved_balance, 'total unchanged');

        $svc->release($reserve, 'provider error');
        $wallet->refresh();
        $this->assertSame(10.0, (float) $wallet->balance);
        $this->assertSame(0.0, (float) $wallet->reserved_balance);
    }

    public function test_commit_debit_consumes_actual_and_releases_leftover(): void
    {
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $svc->grant($wallet, 10.0, 'test seed');
        $model = $this->makeModel();

        $reserve = $svc->reserve($wallet, 2.0, $model);
        // After reserve: balance=8, reserved=2

        // Actual was cheaper than estimated.
        $result = $svc->commitDebit($reserve, providerCost: 0.6, actualUserCost: 1.2);

        $wallet->refresh();
        // 8 (balance after reserve) + 0.8 (leftover released back) = 8.8
        $this->assertSame(8.8, (float) $wallet->balance, 'balance = 8 + 0.8 leftover');
        $this->assertSame(0.0, (float) $wallet->reserved_balance);

        $this->assertSame(WalletTransaction::TYPE_DEBIT, $result['debit']->type);
        $this->assertSame(-1.2, (float) $result['debit']->amount);
        $this->assertSame(-1.2, (float) $result['debit']->reserved_delta, 'debit drops reserved by the spent amount');
        $this->assertSame(0.6, (float) $result['debit']->provider_cost);
        $this->assertSame(1.2, (float) $result['debit']->user_cost);

        $this->assertNotNull($result['leftover']);
        $this->assertSame(WalletTransaction::TYPE_RELEASE, $result['leftover']->type);
        $this->assertSame(0.0, (float) $result['leftover']->amount, 'release is a move, total unchanged');
        $this->assertSame(-0.8, (float) $result['leftover']->reserved_delta);
    }

    public function test_insufficient_funds_throws(): void
    {
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $svc->grant($wallet, 1.0, 'tiny seed');

        $this->expectException(InsufficientFundsException::class);
        $svc->reserve($wallet, 2.0, $this->makeModel());
    }

    public function test_concurrent_reserves_cannot_overcommit(): void
    {
        // We run twice in serial inside a single test; with FOR UPDATE both
        // should see the post-first-reserve state, so the second one trips
        // InsufficientFunds. The point: the second reserve must respect the
        // first one's hold, not see the un-reserved balance.
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $svc->grant($wallet, 5.0, 'seed');
        $model = $this->makeModel();

        $svc->reserve($wallet, 3.0, $model);

        $this->expectException(InsufficientFundsException::class);
        $svc->reserve($wallet->fresh(), 3.0, $model);
    }

    public function test_ledger_invariant_holds_after_full_cycle(): void
    {
        // Two invariants the reconciliation job will enforce daily:
        //   balance + reserved_balance == sum(amount)
        //   reserved_balance           == sum(reserved_delta)
        // We exercise grant → reserve → commit → grant → reserve → release
        // and assert both after every step.
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $model = $this->makeModel();

        $assertInvariant = function () use ($wallet) {
            $rows = WalletTransaction::where('wallet_id', $wallet->id)->get();
            $sumAmount = (float) $rows->sum('amount');
            $sumReservedDelta = (float) $rows->sum('reserved_delta');
            $wallet->refresh();
            $total = (float) $wallet->balance + (float) $wallet->reserved_balance;
            $reserved = (float) $wallet->reserved_balance;

            $this->assertEqualsWithDelta(
                $sumAmount, $total, 1e-6,
                "Total funds invariant broken: sum(amount)=$sumAmount total=$total",
            );
            $this->assertEqualsWithDelta(
                $sumReservedDelta, $reserved, 1e-6,
                "Reserved invariant broken: sum(reserved_delta)=$sumReservedDelta reserved=$reserved",
            );
        };

        $svc->grant($wallet, 5.0, 'seed');
        $assertInvariant();

        $r1 = $svc->reserve($wallet->fresh(), 2.0, $model);
        $assertInvariant();

        $svc->commitDebit($r1, 0.5, 1.0);
        $assertInvariant();

        $svc->grant($wallet->fresh(), 3.0, 'top-up grant');
        $assertInvariant();

        $r2 = $svc->reserve($wallet->fresh(), 1.5, $model);
        $assertInvariant();

        $svc->release($r2, 'provider 500');
        $assertInvariant();
    }

    public function test_available_balance_is_balance_alone_not_minus_reserved(): void
    {
        // reserve() already moves held funds OUT of `balance`, so available
        // must equal `balance` — subtracting reserved_balance again would
        // count every in-flight hold twice (regression for the double-
        // subtraction bug that made the UI/402-gate undershoot mid-run).
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $svc->grant($wallet, 10.0, 'seed');

        $svc->reserve($wallet->fresh(), 3.0, $this->makeModel());

        $wallet->refresh();
        $this->assertSame(7.0, (float) $wallet->balance);
        $this->assertSame(3.0, (float) $wallet->reserved_balance);
        $this->assertSame(7.0, $wallet->availableBalance(), 'available = balance, hold must not be subtracted twice');
    }

    public function test_payment_source_ref_is_unique_at_db_level(): void
    {
        // The application-level idempotency check is read-then-insert; the
        // unique index is what actually closes the concurrent-webhook race.
        $svc = app(WalletService::class);
        $wallet = $svc->forUser($this->makeUser());

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'amount' => 5.0,
            'reserved_delta' => 0,
            'payment_source_ref' => 'race_ref_1',
            'created_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'amount' => 5.0,
            'reserved_delta' => 0,
            'payment_source_ref' => 'race_ref_1',
            'created_at' => now(),
        ]);
    }

    public function test_deposit_is_idempotent_by_payment_source_ref(): void
    {
        $svc = app(WalletService::class);
        $user = $this->makeUser();
        $wallet = $svc->forUser($user);
        $startBalance = (float) $wallet->balance;

        $first = $svc->deposit($wallet, 5.0, 'paddle_evt_abc');
        $balanceAfterFirst = (float) $wallet->fresh()->balance;

        $second = $svc->deposit($wallet->fresh(), 5.0, 'paddle_evt_abc');

        $this->assertSame($first->id, $second->id, 'duplicate webhook must return original row');

        // Second deposit must not move the balance — irrespective of any
        // side effects (first-deposit bonus observer, etc.) that the first
        // deposit may have legitimately triggered.
        $this->assertSame(
            $balanceAfterFirst,
            (float) $wallet->fresh()->balance,
            'duplicate webhook must not change balance from post-first state',
        );
    }
}
