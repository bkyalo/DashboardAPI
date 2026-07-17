<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pre-warm grader + farmer-anomaly cache every day at 05:30
// so the first user of the day hits cache instead of the 40-second query.
Schedule::command('cache:warm-collection')->dailyAt('05:30');
