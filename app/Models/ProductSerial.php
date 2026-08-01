<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductSerial extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'imei', 'warranty_expiry', 'cost', 'status'];

    protected function casts(): array
    {
        return [
            'warranty_expiry' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** The sale_item this unit was sold on, if any — see SaleItem::product_serial_id. */
    public function saleItem()
    {
        return $this->hasOne(SaleItem::class, 'product_serial_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'in_stock');
    }

    public function isUnderWarranty(): bool
    {
        return $this->warranty_expiry !== null && $this->warranty_expiry->isFuture();
    }
}
