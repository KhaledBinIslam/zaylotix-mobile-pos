<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use Illuminate\Console\Command;

/**
 * CLI counterpart to the admin "Public Self-Signup" On/Off toggle
 * (Admin\SiteSettingController::updateSignupEnabled) — for flipping it
 * from this host without going through the admin UI first.
 */
class SiteSetSignupEnabled extends Command
{
    protected $signature = 'site:set-signup-enabled {--enabled=}';

    protected $description = 'Sets whether /signup (public self-registration) is open.';

    public function handle(): int
    {
        $raw = $this->option('enabled');
        if (! in_array($raw, ['0', '1'], true)) {
            $this->error('--enabled=1 বা --enabled=0 দিতে হবে।');

            return self::FAILURE;
        }

        $enabled = $raw === '1';
        $setting = SiteSetting::current();
        $before = $setting->signup_enabled ? 'on' : 'off';
        $setting->update(['signup_enabled' => $enabled]);

        $this->info('signup_enabled: '.$before.' → '.($enabled ? 'on' : 'off'));

        return self::SUCCESS;
    }
}
