<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Blanket ceiling on the mobile/PWA API (checkout, product lookups,
        // barcode scans, background polling) — generous enough for a busy
        // shop with several cashiers scanning at once, but stops a runaway
        // client or a compromised token from hammering the DB.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login (web/API/admin) all throttle via a 5-attempt/IP+account
        // RateLimiter check of their own — signup had no equivalent at all,
        // meaning unlimited shop/account creation attempts from one IP.
        // Not a credential-guessing risk like login, but an unthrottled
        // resource-exhaustion/spam-shop-creation one.
        RateLimiter::for('signup', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        // Behind a reverse proxy in production, requests arrive over plain
        // HTTP internally — without this, generated URLs (redirects, asset
        // links, Storage::url) come back as http:// even on a live https://
        // domain, breaking mixed-content and secure-cookie behavior.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // A logged-in super-admin can act on any tenant model — but only
        // when the admin guard is the one authenticated. A shop user is
        // never granted this bypass, so this can't be used to cross tenants.
        Gate::before(function ($user) {
            if (Auth::guard('admin')->check() && Auth::guard('admin')->id()) {
                return true;
            }

            return null;
        });
    }
}
