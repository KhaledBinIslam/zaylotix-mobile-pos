<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'quotation_id', 'product_id', 'product_name', 'qty', 'price', 'discount'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}
