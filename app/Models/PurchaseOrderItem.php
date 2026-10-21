<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'purchase_order_id', 'product_id', 'product_variant_id',
        'product_name', 'variant_label', 'qty_ordered', 'qty_received', 'cost',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'cost' => 'decimal:2',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** How much of this line is still outstanding — never negative even if something upstream over-received by a rounding hair. */
    public function remainingQty(): float
    {
        return max(0, (float) $this->qty_ordered - (float) $this->qty_received);
    }
}
