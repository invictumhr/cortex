<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily wallet reconciliation — runs at 04:00 server time, after all timezones'
// peak hours, before EU business start. Drift logs critical + table output.
Schedule::command('cortex:wallet-reconcile')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Hourly orphan-reserve sweep — releases RESERVE rows whose job died before
// settling. Reconciliation can't catch these (invariants still hold), so the
// frozen funds would otherwise never return to the user's spendable balance.
Schedule::command('cortex:wallet-release-stale')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Housekeeping: failed_jobs rows older than a week have been triaged or never
// will be — without pruning the table grows unbounded (payloads are big).
Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->daily()
    ->onOneServer();

// Ephemeral architect personas with no messages and no chat are leftovers of
// failed composer runs — sweep them weekly.
Schedule::command('cortex:prune')
    ->weekly()
    ->onOneServer();
