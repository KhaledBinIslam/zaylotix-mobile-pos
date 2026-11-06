<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A brand the owner tracks per product OR per variant (helmet: four brands inside one product) — see the Phase 4 migration. */
class Brand extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}
