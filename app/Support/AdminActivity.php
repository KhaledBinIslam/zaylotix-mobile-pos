<?php

namespace App\Support;

use App\Models\AdminActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Platform-level audit log — mirrors App\Support\Activity (the per-shop
 * version) exactly, but for actions an admin takes across the whole
 * platform (creating/editing/deleting a shop, impersonating an owner, ...).
 */
class AdminActivity
{
    public static function log(string $action, string $description, ?Model $subject = null, array $meta = []): void
    {
        AdminActivityLog::create([
            'admin_id' => Auth::guard('admin')->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->id,
            'description' => $description,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
