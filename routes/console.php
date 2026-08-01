<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('zaylotix:payment-reminders')->dailyAt('08:00');
Schedule::command('zaylotix:expire-subscriptions')->dailyAt('00:05');
Schedule::command('zaylotix:low-stock-alerts')->dailyAt('09:00');
Schedule::command('zaylotix:expiry-alerts')->dailyAt('09:00');
Schedule::command('zaylotix:backup')->dailyAt('02:00');
