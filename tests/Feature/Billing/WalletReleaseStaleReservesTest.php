<?php

namespace Tests\Feature\Billing;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletReleaseStaleReservesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Sweep User '.uniqid(),
            'email' => 'sweep'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);
    }

    private function makeModel(): AiModel
    {
        $provider = AiProvider::create([
            'slug' => 'sweep-prov-'.uniqid(),
            'name' => 'Sweep Provider',
            'api_key' => 'key',
            'is_active' => true,
        ]);

        return AiModel::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Sweep Model '.uniqid(),
            'model_string' => 'sweep-model-'.uniqid(),
            'input_cost_per_1m_tokens' => 3.0,
            'output_cost_per_1m_tokens' => 15.0,
            'is_active' => true,
        ]);
    }

    public function test_orphaned_old_reserve_is_released_back_to_spendable(): void
    {
        $svc = app(WalletService::class);
        $wallet = $svc->forUser($this->makeUser());
        $svc->grant($wallet, 10.0, 'seed');

        $reserve = $svc->reserve($wallet->fresh(), 4.0, $this->makeModel());
        // Simulate a job that died after reserve(): backdate past the cutoff.
        $reserve->forceFill(['created_at' => now()->subHours(12)])->save();

        $wallet->refresh();
        $this->assertSame(6.0, (float) $wallet->balance);
        $this->assertSame(4.0, (float) $wallet->reserved_balance);

        $this->artisan('cortex:wallet-release-stale', ['--hours' => 6])
            ->assertExitCode(0);

        $wallet->refresh();
        $this->assertSame(10.0, (float) $wallet->balance, 'frozen funds returned to spendable');
        $this->assertSame(0.0, (float) $wallet->reserved_balance);

        $release = WalletTransaction::where('reserved_id', $reserve->id)
            ->where('type', WalletTransaction::TYPE_RELEASE)
            ->first();
        $this->assertNotNull($release, 'sweep must write a RELEASE settlement row');
    }

    public function test_recent_inflight_reserve_is_not_touched(): void
    {
        $svc = app(WalletService::class);
        $wallet = $svc->forUser($this->makeUser());
        $svc->grant($wallet, 10.0, 'seed');

        $svc->reserve($wallet->fresh(), 4.0, $this->makeModel());

        $this->artisan('cortex:wallet-release-stale', ['--hours' => 6])
            ->assertExitCode(0);

        $wallet->refresh();
        $this->assertSame(4.0, (float) $wallet->reserved_balance, 'fresh reserve must stay held');
    }

    public function test_settled_reserve_is_not_swept_even_when_old(): void
    {
        $svc = app(WalletService::class);
        $wallet = $svc->forUser($this->makeUser());
        $svc->grant($wallet, 10.0, 'seed');

        $reserve = $svc->reserve($wallet->fresh(), 4.0, $this->makeModel());
        $svc->commitDebit($reserve, providerCost: 1.0, actualUserCost: 2.0);
        $reserve->forceFill(['created_at' => now()->subHours(48)])->save();

        $balanceBefore = (float) $wallet->fresh()->balance;

        $this->artisan('cortex:wallet-release-stale', ['--hours' => 6])
            ->assertExitCode(0);

        $this->assertSame($balanceBefore, (float) $wallet->fresh()->balance, 'settled reserve must not be re-released');
    }

    public function test_dry_run_reports_but_does_not_release(): void
    {
        $svc = app(WalletService::class);
        $wallet = $svc->forUser($this->makeUser());
        $svc->grant($wallet, 10.0, 'seed');

        $reserve = $svc->reserve($wallet->fresh(), 4.0, $this->makeModel());
        $reserve->forceFill(['created_at' => now()->subHours(12)])->save();

        $this->artisan('cortex:wallet-release-stale', ['--hours' => 6, '--dry-run' => true])
            ->assertExitCode(0);

        $wallet->refresh();
        $this->assertSame(4.0, (float) $wallet->reserved_balance, 'dry run must not move funds');
    }
}
