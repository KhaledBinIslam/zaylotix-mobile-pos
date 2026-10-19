<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\SubscriptionPayment;
use App\Observers\SubscriptionPaymentObserver;
use App\Support\Tenancy;
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

        // Tenancy::shop()'s per-request cache (see docs/SCALING_GUIDE.md) is
        // only correct if it actually ends with the request — plain PHP-FPM
        // resets every static property between requests on its own, but
        // that's an implementation detail of ONE deployment model, not
        // something to quietly rely on: Octane's persistent workers
        // wouldn't reset it at all, and — concretely hit while testing this
        // very fix — neither does Laravel's own feature-test HTTP client,
        // which runs several ->get() calls through the SAME booted app
        // instance. terminating() fires after the response is sent, for a
        // real request and for the test client alike, so this is correct
        // under all three.
        app()->terminating(fn () => Tenancy::forgetShopCache());

        // Generates an ambassador's recurring commission row from whichever
        // path recorded the payment (admin manual entry, bKash/SSLCommerz
        // auto-renewal webhook) -- see AmbassadorCommissionService.
        SubscriptionPayment::observe(SubscriptionPaymentObserver::class);

        // Blanket ceiling on the mobile/PWA API (checkout, product lookups,
        // barcode scans, background polling) — generous enough for a busy
        // shop with several cashiers scanning at once, but stops a runaway
        // client or a compromised token from hammering the DB.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Scale audit (docs/SCALING_GUIDE.md): the web session-based
        // checkout/mutation endpoints (pos.checkout, gateway-checkout,
        // restaurant bill/addItem, returns.process) had NO throttle at
        // all, unlike their API-side equivalents above. Same 120/min
        // ceiling, by logged-in user — a real multi-counter shop never
        // gets close to 2 checkouts/second from one account; this only
        // ever stops a buggy retry loop or a compromised session.
        // Disabled under the test suite: these 5 routes are hit by
        // hundreds of feature tests, many deliberately unauthenticated
        // (permission/validation sad-paths), which all fall back to the
        // same IP key and — confirmed by actually running the suite —
        // blow through 120/min cumulatively across the whole run, failing
        // unrelated tests with a 429 that would never happen one at a time
        // in real usage.
        RateLimiter::for('checkout', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

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
