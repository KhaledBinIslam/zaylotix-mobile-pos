<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement for feature-gated routes — admin controls which
 * shops have which capabilities (see `features` / `shop_features` tables),
 * and this middleware makes sure hiding a button in the UI is backed by a
 * real permission check, not just cosmetics. Usage: ->middleware('feature:barcode_printing')
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $shop = Tenancy::shop();

        if (! $shop || ! $shop->hasFeature($key)) {
            abort(403, 'এই ফিচারটি আপনার দোকানের জন্য চালু নেই। এডমিনের সাথে যোগাযোগ করুন।');
        }

        return $next($request);
    }
}
