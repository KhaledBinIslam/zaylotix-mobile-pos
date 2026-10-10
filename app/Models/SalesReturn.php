<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use BelongsToTenant;

    protected $table = 'returns';

    protected $fillable = [
        'shop_id', 'product_id', 'product_batch_id', 'user_id', 'qty', 'refund', 'phone', 'date',
        'sale_id', 'sale_item_id', 'product_variant_id', 'customer_id', 'exchange_sale_id',
        'type', 'condition', 'cost', 'applied_to_due', 'loyalty_points_deducted',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'refund' => 'decimal:2',
            'cost' => 'decimal:2',
            'applied_to_due' => 'boolean',
            'date' => 'date',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function exchangeSale()
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }
}
