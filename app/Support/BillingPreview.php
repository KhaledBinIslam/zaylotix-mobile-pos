<?php

namespace App\Support;

use App\Http\Controllers\App\PosController;
use App\Support\Gateways\GatewayPreviewRollback;
use Illuminate\Support\Facades\DB;

/**
 * Learns a cart's real subtotal/discount/VAT/total — including a coupon's
 * or redeemed loyalty points' discount — by running the exact same pricing
 * logic PosController::performCheckout() always runs, then rolling back
 * before any Sale/stock change actually commits. Reuses the identical "run
 * the real logic once, then roll back" pattern GatewayPricePreview already
 * proved out for gateway checkouts, generalized to return the full
 * breakdown so the POS billing popup's own displayed total can never drift
 * from what checkout will actually charge once a coupon or redeemed points
 * are involved (see PosController::billingPreview()).
 */
class BillingPreview
{
    public static function compute(array $data, int $shopId, ?int $userId): array
    {
        $result = null;

        try {
            DB::transaction(function () use ($data, $shopId, $userId, &$result) {
                $sale = PosController::performCheckout($data, $shopId, $userId, autoCoverTotal: true);
                $result = [
                    'subtotal' => (float) $sale->subtotal,
                    'discount' => (float) $sale->discount,
                    'service_charge' => (float) $sale->service_charge,
                    'vat' => (float) $sale->vat,
                    'total' => (float) $sale->total,
                ];

                throw new GatewayPreviewRollback();
            });
        } catch (GatewayPreviewRollback) {
            // expected — nothing survives this except $result
        }

        return $result;
    }
}
