<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton row (always id 1) for platform-wide branding — see the
 * migration for why this isn't just a column on Admin.
 */
class SiteSetting extends Model
{
    protected $fillable = ['logo_path', 'reminder_days'];

    protected $appends = ['logo_url'];

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null);
    }

    public static function current(): self
    {
        // explicit default here, not just the column's DB-level default —
        // firstOrCreate()'s returned in-memory instance never picks up a
        // value that only exists because the DB applied its own column
        // default on insert, so the very first read after a fresh install
        // would otherwise see `reminder_days` as null instead of 3
        return static::firstOrCreate(['id' => 1], ['reminder_days' => 3]);
    }
}
