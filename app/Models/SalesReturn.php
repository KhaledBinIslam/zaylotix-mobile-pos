<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use BelongsToTenant;

    protected $table = 'returns';

    protected $fillable = ['shop_id', 'product_id', 'product_batch_id', 'user_id', 'qty', 'refund', 'phone', 'date'];

    protected function casts(): array
    {
        return [
            'refund' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
