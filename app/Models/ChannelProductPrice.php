<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One channel's price override for one product — see docs/FIX_PLAN.md's Phase 31 design note. */
class ChannelProductPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'channel_id', 'product_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function channel()
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
