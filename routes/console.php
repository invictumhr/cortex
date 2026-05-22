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
