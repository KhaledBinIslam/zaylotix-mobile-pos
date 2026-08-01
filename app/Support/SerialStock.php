<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSerial;

/**
 * Per-unit IMEI/serial tracking layered on top of a product's plain stock
 * count — mirrors BatchStock's relationship to Product.stock: stock is
 * still the number that gates checkout, this is optional supplementary
 * bookkeeping for after-sales warranty lookup and audit. A cashier who
 * doesn't scan/type an IMEI at sale time just sells normally; nothing here
 * ever blocks a sale.
 */
class SerialStock
{
    /** Splits a textarea's worth of pasted/typed IMEIs (one per line, or comma-separated) into a clean list. */
    public static function parseList(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return collect(preg_split('/[\r\n,]+/', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }

    /** @param  string[]  $imeis */
    public static function receive(Product $product, array $imeis, ?string $warrantyExpiry, ?float $cost): void
    {
        foreach ($imeis as $imei) {
            ProductSerial::create([
                'shop_id' => $product->shop_id,
                'product_id' => $product->id,
                'imei' => $imei,
                'warranty_expiry' => $warrantyExpiry,
                'cost' => $cost ?? $product->cost,
                'status' => 'in_stock',
            ]);
        }
    }

    /** Marks the named unit sold. Returns null (never throws) if that exact IMEI isn't a known in-stock unit of this product — the caller decides whether that's fatal. */
    public static function sell(Product $product, string $imei): ?ProductSerial
    {
        $serial = ProductSerial::where('product_id', $product->id)
            ->where('imei', $imei)
            ->available()
            ->lockForUpdate()
            ->first();

        $serial?->update(['status' => 'sold']);

        return $serial;
    }

    public static function restore(?int $serialId): void
    {
        if ($serialId) {
            ProductSerial::whereKey($serialId)->update(['status' => 'in_stock']);
        }
    }
}
