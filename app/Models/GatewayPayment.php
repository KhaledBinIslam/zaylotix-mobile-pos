<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GatewayPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'user_id', 'provider', 'reference', 'gateway_transaction_id',
        'amount', 'status', 'checkout_payload', 'sale_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'checkout_payload' => 'array',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
