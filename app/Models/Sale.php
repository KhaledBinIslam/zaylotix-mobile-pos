<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'customer_id', 'table_order_id', 'user_id', 'invoice_no', 'date', 'time',
        'subtotal', 'discount', 'is_complimentary', 'service_charge', 'vat', 'total', 'profit', 'payment_mode', 'sale_type', 'prescription_note', 'coupon_code',
        'points_earned', 'points_redeemed',
        'voided_at', 'voided_reason', 'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'is_complimentary' => 'boolean',
            'vat' => 'decimal:2',
            'total' => 'decimal:2',
            'profit' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Voided sales stay in the table (unlike the admin's hard-delete tool)
     * for accountability, but must never count as real revenue again —
     * every report/home/export query that sums or lists sales should simply
     * never see one unless it explicitly asks via withVoided(). Only the
     * Sales history list/show page needs that, since an owner still needs
     * to look up what got voided and why.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('active', fn ($query) => $query->whereNull('voided_at'));
    }

    public function scopeWithVoided($query)
    {
        return $query->withoutGlobalScope('active');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Named voidedByUser, not voidedBy — the relation and the raw voided_by
    // FK column would otherwise share the same snake_cased JSON key when
    // serialized, and the relation's array-merge would silently shadow the
    // plain column value.
    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * Only set for a sale that came from billing a restaurant table order —
     * lets Sales/Show.vue offer the combined kitchen+customer print/WhatsApp
     * for restaurant-origin sales without affecting plain POS sales.
     *
     * A `belongsTo` via `table_order_id`, not the table order's own
     * `sale_id` — one table order can now produce several sales via
     * split-bill, so the FK direction had to move here to let each split
     * sale still resolve its own order context (table name, order source,
     * kitchen note, waiter) for its own receipt.
     */
    public function tableOrder()
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id');
    }
}
