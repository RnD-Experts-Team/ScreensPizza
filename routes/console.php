<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A work day is final 16 hours after it ends (the longest shift), so yesterday is final by
// 16:00 in the stores' clock. Each pass rebuilds the last seven final days, catching segments
// a browser sent late.
Schedule::command('usage:aggregate-daily')
    ->dailyAt('17:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer();
