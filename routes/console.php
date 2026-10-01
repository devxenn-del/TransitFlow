<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bill each active company the day after its billing period ends (App\Actions\GenerateBillingStatement).
Schedule::command('billing:generate')->dailyAt('01:00')->withoutOverlapping();
