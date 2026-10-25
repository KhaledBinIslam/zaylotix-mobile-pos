<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** "Online Business" module, part 2 — see docs/FIX_PLAN.md's Phase 32 design note. */
class OnlineOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'channel_id', 'sale_id', 'order_number', 'external_reference', 'status',
        'courier_id', 'tracking_id', 'delivery_charge', 'cod_amount', 'campaign_tag',
        'delivered_at', 'cancelled_at',
    ];

    /** Every status an order can be in — see docs/FIX_PLAN.md's Phase 32 design note for what each one does and doesn't move. */
    public const STATUSES = [
        'pending', 'confirmed', 'packed', 'handed_to_courier', 'in_transit',
        'delivered', 'failed', 'returning', 'returned', 'cancelled',
    ];

    /** Allowed next status(es) from each current one — a transition not listed here is rejected. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['packed', 'cancelled'],
        'packed' => ['handed_to_courier', 'cancelled'],
        'handed_to_courier' => ['in_transit', 'cancelled'],
        'in_transit' => ['delivered', 'failed', 'cancelled'],
        'delivered' => ['returning'],
        'failed' => ['returning', 'cancelled'],
        'returning' => ['returned'],
        'returned' => [],
        'cancelled' => [],
    ];

    /** Real stock/money already moved and must never be touched again by a later transition — see the Delivered/Cancel handling in OnlineOrderController. */
    public const TERMINAL_STATUSES = ['delivered', 'returned', 'cancelled'];

    protected function casts(): array
    {
        return [
            'delivery_charge' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function channel()
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    /**
     * withoutGlobalScope('active') — Sale's own scope hides a not-yet-
     * delivered online order's Sale from every normal query (see Sale::
     * booted()); this relation is THIS order's own reference to exactly
     * that Sale, so it must see it regardless of status, same as
     * Sale::withVoided() already does for a direct deep-link.
     */
    public function sale()
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScope('active');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
