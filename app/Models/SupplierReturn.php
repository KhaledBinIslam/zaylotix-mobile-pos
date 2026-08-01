<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupplierReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'supplier_id', 'supplier', 'product_id', 'qty', 'reason', 'settlement_method', 'amount', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function supplierModel()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
