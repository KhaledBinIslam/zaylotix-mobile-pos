<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ServiceTicket extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'customer_id', 'product_id', 'product_serial_id', 'device_label',
        'problem_description', 'technician_name', 'status', 'is_warranty_claim',
        'received_at', 'promised_at', 'delivered_at', 'sale_id', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_warranty_claim' => 'boolean',
            'received_at' => 'datetime',
            'promised_at' => 'date:Y-m-d',
            'delivered_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Every line ever added, billed or not — callers that want just the still-open tab should eager-load with a `whereNull('sale_id')` constraint (see TableOrder's matching docblock). */
    public function items()
    {
        return $this->hasMany(ServiceTicketItem::class);
    }

    /** The running total of whatever's still unbilled. */
    public function total(): float
    {
        return (float) $this->items->sum(fn (ServiceTicketItem $i) => (float) $i->price * (float) $i->qty);
    }
}
