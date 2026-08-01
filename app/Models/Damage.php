<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Damage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'product_batch_id', 'qty', 'reason', 'loss', 'date'];

    protected function casts(): array
    {
        return [
            'loss' => 'decimal:2',
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
