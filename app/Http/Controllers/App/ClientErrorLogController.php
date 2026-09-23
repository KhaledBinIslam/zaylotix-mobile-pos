<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * A client-side JS error (an unhandled promise rejection, a failed fetch
 * with no visible reason) used to be genuinely invisible once shipped —
 * only ever seen in a cashier's own devtools, which nobody ever opens, so
 * a report like "একটা ভুল হয়েছে টোস্ট দেখাচ্ছে" had no trail to follow. This
 * writes the real error into the normal Laravel log instead (see
 * storage/logs/laravel.log, or `tail` it over SSH), tagged with which shop/
 * user/page hit it, so a report like that is actually investigable
 * afterward instead of needing to be reproduced blind.
 */
class ClientErrorLogController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:4000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'string', 'max:200'],
        ]);

        Log::warning('[client-js-error] '.$data['message'], [
            'shop_id' => Tenancy::id(),
            'user_id' => Auth::id(),
            'context' => $data['context'] ?? null,
            'url' => $data['url'] ?? null,
            'stack' => $data['stack'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }
}
