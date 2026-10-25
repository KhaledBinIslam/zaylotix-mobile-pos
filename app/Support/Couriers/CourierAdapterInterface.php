<?php

namespace App\Support\Couriers;

use App\Models\OnlineOrder;

/**
 * Generic contract for a real courier API integration (Pathao/Steadfast/
 * RedX) — see docs/FIX_PLAN.md's Phase 33 design note. Only
 * ManualCourierAdapter exists today; a real adapter plugs in later
 * without touching OnlineOrderController.
 */
interface CourierAdapterInterface
{
    /** Hands the order off to the courier's own system — returns a tracking id, or null if nothing to do (manual adapter). */
    public function createShipment(OnlineOrder $order): ?string;

    /** Pulls the courier's own current status for this shipment, or null if not supported. */
    public function trackShipment(OnlineOrder $order): ?string;
}
