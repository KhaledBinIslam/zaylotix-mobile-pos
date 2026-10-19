<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * See docs/SESSION_FIX.md. Every raw-fetch call site (POS/Clothing/
 * Restaurant checkout, useOfflineSync.js's replay) carries its CSRF token
 * from a <meta name="csrf-token"> value that's set ONCE, at the moment the
 * page document first loaded, and never refreshed again for the rest of
 * that tab's life — unlike Inertia's own axios-driven requests, which
 * self-heal automatically because Laravel re-issues the XSRF-TOKEN cookie
 * fresh on every single response. A shop left open for hours (exactly the
 * normal case — a cashier doesn't reload their POS tab all day) can have
 * its session's real token rotate or the session itself get swept and
 * recreated server-side with no way for that stale meta tag to know. This
 * endpoint is what fetchWithSessionRetry.js calls to pull a current,
 * correct token and patch both its own retry request AND the page's meta
 * tag (so every later raw-fetch call benefits too, not just the one that
 * happened to hit the stale one first) — GET, so it never itself needs a
 * CSRF token to reach, same as the meta tag's underlying csrf_token() call.
 */
class CsrfTokenController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['token' => $request->session()->token()]);
    }
}
