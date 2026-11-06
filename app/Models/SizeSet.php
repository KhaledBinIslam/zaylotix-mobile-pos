<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A named, ordered list of sizes the owner reuses ("Shoe 39–44", "Peak cap 20.5–23") — see the Phase 3 migration. Half sizes are stored as "20.5"; display turns them into "20½". */
class SizeSet extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'sizes'];

    protected function casts(): array
    {
        return ['sizes' => 'array'];
    }
}
