<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One-line append to the shop's activity log — called from the point of an
 * existing mutation (void, stock-in, payment, delete, ...), never a separate
 * step the caller has to remember to wire up elsewhere.
 */
class Activity
{
    public static function log(string $action, string $description, ?Model $subject = null, array $meta = []): void
    {
        // Same web-or-sanctum guard fallback as EnsureUserPermission — the
        // acting user is whichever guard actually authenticated this
        // request (web session or the mobile/PWA API token).
        $user = Auth::guard('web')->user() ?? Auth::guard('sanctum')->user();

        ActivityLog::create([
            'shop_id' => Tenancy::id(),
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->id,
            'description' => $description,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
