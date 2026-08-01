<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an app route by the logged-in user's own permission grants, not the
 * shop's — this is the owner-to-cashier layer, separate from (and inside)
 * the admin-to-shop `feature` layer. The owner role always passes (see
 * User::hasPermission()); usage: ->middleware('perm:accounts')
 */
class EnsureUserPermission
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        // Same user/session provider serves both the web-session shop app
        // and the Sanctum-token mobile/PWA API (see Tenancy::id()) — this
        // must check whichever guard actually authenticated the request, or
        // every API call would 403 (no 'web' session exists there) while a
        // cashier without this permission could still reach the same action
        // through the API, since it'd never even get this far.
        $user = Auth::guard('web')->user() ?? Auth::guard('sanctum')->user();

        if (! $user || ! $user->hasPermission($key)) {
            abort(403, 'এই অংশে প্রবেশের অনুমতি আপনার নেই। দোকান মালিকের সাথে কথা বলুন।');
        }

        return $next($request);
    }
}
