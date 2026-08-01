<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One tender line for a sale — actual money received via a specific method. Whatever isn't covered here becomes the attached customer's due. */
class SalePayment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'sale_id', 'method', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
