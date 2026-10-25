<?php

namespace App\Support;

use App\Models\Courier;

/**
 * Turns a courier's own charge rule into an estimated cost for one
 * parcel — see docs/FIX_PLAN.md's Phase 33 design note. Always just an
 * estimate (shown editable on the order), never a blocker.
 */
class CourierCharge
{
    public static function estimate(Courier $courier, ?float $weight = null, ?bool $outsideDhaka = null): float
    {
        $config = $courier->charge_config ?? [];

        return match ($courier->charge_type) {
            'weight' => (float) ($config['base'] ?? 0) + (float) ($config['per_kg'] ?? 0) * ($weight ?? 0),
            'zone' => $outsideDhaka
                ? (float) ($config['outside_dhaka'] ?? 0)
                : (float) ($config['inside_dhaka'] ?? 0),
            default => (float) ($config['amount'] ?? 0),
        };
    }
}
