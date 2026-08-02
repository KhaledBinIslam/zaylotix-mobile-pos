<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide, admin-managed per-screen "how do I do this" hint text -- see HowToHint.vue and its screen-key prop. */
class ScreenGuide extends Model
{
    protected $fillable = ['screen_key', 'label', 'text_bn', 'text_en', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
