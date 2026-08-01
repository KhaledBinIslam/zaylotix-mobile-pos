<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    // Admin-managed, not tenant-scoped to the shop user (shop users never
    // see billing records — only the admin panel reads/writes this table).
    protected $fillable = [
        'shop_id', 'admin_id', 'plan', 'amount', 'month', 'method', 'paid_on', 'next_due', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'next_due' => 'date',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
