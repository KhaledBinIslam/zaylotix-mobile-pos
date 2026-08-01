<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        $middleware->statefulApi();

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'shop' => \App\Http\Middleware\EnsureShopUser::class,
            'subscription' => \App\Http\Middleware\CheckSubscriptionActive::class,
            'sales.mode' => \App\Http\Middleware\CheckSalesMode::class,
            'api.subscription' => \App\Http\Middleware\EnsureApiShopActive::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'perm' => \App\Http\Middleware\EnsureUserPermission::class,
            'owner' => \App\Http\Middleware\EnsureOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error a shop user or admin can hit — missing record, no
        // permission, expired session, or a genuine server crash — must show
        // a branded, Bengali, explained page instead of Laravel's raw/blank
        // default. Without this, an Inertia XHR that errors just leaves the
        // UI sitting there with nothing visible happening.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('error', 'সেশনের মেয়াদ শেষ হয়ে গেছে — আবার চেষ্টা করুন।');
            }

            if (! in_array($status, [403, 404, 429, 500, 503], true)) {
                return $response;
            }

            $generic = match ($status) {
                403 => $exception->getMessage() ?: 'এই কাজের অনুমতি নেই।',
                404 => 'যা খুঁজছেন তা পাওয়া যায়নি — মুছে ফেলা হয়ে থাকতে পারে।',
                429 => 'অনেক চেষ্টা হয়ে গেছে, একটু পর আবার চেষ্টা করুন।',
                default => 'সার্ভারে সাময়িক সমস্যা হয়েছে। একটু পর আবার চেষ্টা করুন।',
            };

            // Plain JSON/fetch callers (POS checkout, barcode lookup) need a
            // JSON body back, not an HTML/Inertia page, so their existing
            // error handling (data.message) keeps working unchanged.
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $generic], $status);
            }

            // Never forward a raw framework/DB exception message to the
            // browser for 404/429/500/503 — a "no query results for model
            // [App\Models\Product] 91" or a SQL error can leak internal
            // structure. 403s are always our own deliberately-written
            // abort(403, '...') messages, so those are safe to show as-is.
            return Inertia::render('Error', [
                'status' => $status,
                'message' => $status === 403 ? $exception->getMessage() : null,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
