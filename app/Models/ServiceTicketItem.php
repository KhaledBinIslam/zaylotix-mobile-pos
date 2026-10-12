<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceTicketItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'service_ticket_id', 'product_id', 'is_labor', 'label', 'qty', 'cost', 'price', 'sale_id'];

    protected function casts(): array
    {
        return [
            'is_labor' => 'boolean',
            'qty' => 'decimal:3',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
