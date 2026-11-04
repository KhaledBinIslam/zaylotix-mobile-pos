<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One batch of goods entering stock, priced — see the stock_intakes migration and App\Support\IntakeLedger, the only writer. */
class StockIntake extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'product_variant_id', 'user_id', 'qty', 'unit_cost', 'total_cost', 'source'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
