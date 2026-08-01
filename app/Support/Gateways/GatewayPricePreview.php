<?php

namespace App\Support\Gateways;

use App\Http\Controllers\App\PosController;
use Illuminate\Support\Facades\DB;

/**
 * Learns what a cart's real total is — same pricing, discount, VAT, and
 * stock-availability validation PosController::performCheckout always
 * runs — without actually creating a Sale or moving any stock, since a
 * gateway needs the amount before the customer has paid anything at all.
 *
 * Deliberately reuses performCheckout itself rather than a second, separate
 * pricing routine: running the real logic once and rolling it back is the
 * only way to guarantee this preview and the sale eventually created from
 * the same cart can never compute a different total from each other.
 */
class GatewayPricePreview
{
    public static function total(array $data, int $shopId, ?int $userId): float
    {
        $total = null;

        try {
            DB::transaction(function () use ($data, $shopId, $userId, &$total) {
                $sale = PosController::performCheckout($data, $shopId, $userId, autoCoverTotal: true);
                $total = (float) $sale->total;

                throw new GatewayPreviewRollback();
            });
        } catch (GatewayPreviewRollback) {
            // expected — the trial Sale/stock movement is discarded, only $total survives
        }

        return $total;
    }
}
