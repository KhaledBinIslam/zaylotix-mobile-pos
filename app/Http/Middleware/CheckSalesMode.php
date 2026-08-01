<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards any route that assumes barcode scanning is available. A shop whose
 * admin-configured sales_mode is "manual" must never be able to reach the
 * scan endpoint, even by hitting the URL/API directly — the button being
 * hidden client-side is not enough.
 */
class CheckSalesMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = Auth::guard('web')->user()?->shop ?? Auth::guard('sanctum')->user()?->shop;

        if ($shop && $shop->sales_mode === 'manual') {
            abort(403, 'এই দোকানের জন্য স্ক্যান সুবিধা বন্ধ আছে।');
        }

        return $next($request);
    }
}
