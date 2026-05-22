<?php

namespace App\Providers;

use App\Listeners\GrantSignupCredit;
use App\Models\WalletTransaction;
use App\Observers\WalletTransactionObserver;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Billing wiring: signup credit lands when email is verified (so a
        // throwaway registration that never confirms costs us nothing), and
        // the first-deposit bonus is wired to the wallet ledger observer.
        Event::listen(Verified::class, GrantSignupCredit::class);
        WalletTransaction::observe(WalletTransactionObserver::class);
    }
}
