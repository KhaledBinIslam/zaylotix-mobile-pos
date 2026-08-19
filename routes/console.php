<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Root-cause fix for a live production bug found via the real error log:
// Schedule::command(...) always spawns a REAL OS subprocess to run that
// command (via Symfony Process -> proc_open), even when nothing here asks
// for ->runInBackground() -- that subprocess spawn is how Laravel's
// scheduler isolates each command by design, not an opt-in. This host has
// proc_open (and exec/shell_exec) disabled entirely -- the exact same
// constraint that already broke storage:link and raw mysqldump earlier --
// so EVERY one of these five jobs has been throwing
// "The Process class relies on proc_open..." and silently never running,
// every single time cron fired schedule:run, since go-live. That includes
// the nightly backup: BackupDatabase.php's own move to pure-PHP
// ifsnop/mysqldump-php (fixing exec() *inside* the command) never mattered,
// because the scheduler couldn't even invoke the command's handle() in the
// first place.
//
// Schedule::call() with Artisan::call() runs the command's handle() method
// IN-PROCESS -- no subprocess, no proc_open, nothing for this host to
// refuse -- while keeping the exact same scheduling API (dailyAt, etc.)
// and the exact same times as before.
Schedule::call(fn () => Artisan::call('zaylotix:payment-reminders'))->dailyAt('08:00');
Schedule::call(fn () => Artisan::call('zaylotix:expire-subscriptions'))->dailyAt('00:05');
Schedule::call(fn () => Artisan::call('zaylotix:low-stock-alerts'))->dailyAt('09:00');
Schedule::call(fn () => Artisan::call('zaylotix:expiry-alerts'))->dailyAt('09:00');
Schedule::call(fn () => Artisan::call('zaylotix:backup'))->dailyAt('02:00');
