<?php

namespace App\Support\Couriers;

use App\Models\OnlineOrder;

/** The only adapter that exists today — the owner enters tracking_id/status by hand, same as the whole module has worked since Part 2. */
class ManualCourierAdapter implements CourierAdapterInterface
{
    public function createShipment(OnlineOrder $order): ?string
    {
        return null;
    }

    public function trackShipment(OnlineOrder $order): ?string
    {
        return null;
    }
}
