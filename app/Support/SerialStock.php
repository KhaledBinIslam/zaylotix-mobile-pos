<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Per-unit IMEI/serial tracking layered on top of a product's plain stock
 * count — mirrors BatchStock's relationship to Product.stock: stock is
 * still the number that gates checkout, this is optional supplementary
 * bookkeeping for after-sales warranty lookup and audit.
 *
 * Rules (owner decision 2026-11-28, QA D8):
 *  - a cashier who doesn't scan/type an IMEI, or types one that was never registered anywhere, still sells normally;
 *  - an IMEI the business KNOWS is NOT sellable here — already sold (any branch), written off, in another branch's
 *    stock, or belonging to a different product — is refused, on the server, and the whole bill with it;
 *  - an IMEI becomes sellable again only by being back in valid stock: a resalable return/exchange or a void (restore()),
 *    or an authorised stock correction — registering it again through stock-in (receive()).
 * Every check runs inside the checkout/stock-in transaction, with the IMEI's rows locked across ALL branches.
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

    /** every serial row of these IMEIs anywhere in the business, keyed by IMEI */
    private static function businessRows(array $imeis, int $shopId, bool $lock): Collection
    {
        $q = ProductSerial::withoutGlobalScopes()
            ->whereIn('shop_id', Tenancy::businessShopIds($shopId) ?: [$shopId])
            ->whereIn('imei', $imeis);

        return ($lock ? $q->lockForUpdate() : $q)->get()->groupBy('imei');
    }

    /**
     * Dry check that a list can be registered; writes nothing. Returns, per IMEI, the existing row to RE-ACTIVATE (a
     * sold unit of the same product at this shop, i.e. an authorised correction) or null (create a new row).
     *
     * @param  string[]  $imeis
     * @return Collection<string, ?ProductSerial>
     *
     * @throws ValidationException
     */
    public static function assertReceivable(array $imeis, ?Product $product, int $shopId, bool $lock = false): Collection
    {
        $errors = [];
        foreach (array_count_values($imeis) as $imei => $times) {
            if ($times > 1) {
                $errors[] = "IMEI {$imei} তালিকায় একাধিকবার আছে — প্রতিটি ডিভাইসের IMEI আলাদা হতে হবে।";
            }
        }

        $rows = self::businessRows($imeis, $shopId, $lock);
        $plan = collect();
        foreach (array_unique($imeis) as $imei) {
            $known = $rows->get($imei, collect());
            $inStock = $known->first(fn (ProductSerial $r) => $r->status === 'in_stock');
            if ($inStock) {
                $errors[] = "IMEI {$imei} ইতিমধ্যে স্টকে আছে".((int) $inStock->shop_id !== $shopId ? ' (অন্য শাখায়)' : '').' — একই IMEI দুইবার যোগ করা যাবে না।';

                continue;
            }
            $mine = $known->first(fn (ProductSerial $r) => (int) $r->shop_id === $shopId);
            if ($mine && (! $product || (int) $mine->product_id !== (int) $product->id)) {
                $errors[] = "IMEI {$imei} আগে অন্য একটি পণ্য হিসেবে নিবন্ধিত/বিক্রি হয়েছে — এই পণ্যে যোগ করা যাবে না।";

                continue;
            }
            $plan->put($imei, $mine); // sold (or written-off) unit of this very product: an authorised correction
        }

        if ($errors) {
            throw ValidationException::withMessages(['imeis' => array_values(array_unique($errors))]);
        }

        return $plan;
    }

    /**
     * Registers units in stock. All-or-nothing: if any IMEI in the list is refused, none is written (the caller's
     * transaction rolls back). A SOLD unit of the same product is put back in stock — the authorised stock correction.
     *
     * @param  string[]  $imeis
     *
     * @throws ValidationException
     */
    public static function receive(Product $product, array $imeis, ?string $warrantyExpiry, ?float $cost): void
    {
        $plan = self::assertReceivable($imeis, $product, (int) $product->shop_id, true);

        foreach ($plan as $imei => $existing) {
            if ($existing) {
                $was = $existing->status;
                $existing->update([
                    'status' => 'in_stock',
                    'warranty_expiry' => $warrantyExpiry ?? $existing->warranty_expiry,
                    'cost' => $cost ?? $existing->cost ?? $product->cost,
                ]);
                Activity::log('serial.reregistered', "IMEI {$imei} ('{$product->name}') স্টক সংশোধনের মাধ্যমে আবার স্টকে আনা হয়েছে (আগের অবস্থা: {$was}).", $existing);

                continue;
            }
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

    /**
     * Marks the named unit sold — and REFUSES (ValidationException, so the whole bill rolls back) an IMEI the business
     * knows but cannot sell here. Returns null only for an IMEI that was never registered anywhere (documented: an
     * unregistered IMEI never blocks a sale). Call inside the checkout transaction.
     *
     * @throws ValidationException
     */
    public static function sell(Product $product, string $imei): ?ProductSerial
    {
        $known = self::businessRows([$imei], (int) $product->shop_id, true)->get($imei, collect());
        if ($known->isEmpty()) {
            return null;
        }

        $own = $known->first(fn (ProductSerial $r) => (int) $r->shop_id === (int) $product->shop_id
            && (int) $r->product_id === (int) $product->id && $r->status === 'in_stock');
        if ($own) {
            $own->update(['status' => 'sold']);

            return $own;
        }

        $soldRow = $known->first(fn (ProductSerial $r) => $r->status !== 'in_stock');
        if ($soldRow) {
            $on = SaleItem::withoutGlobalScopes()->where('product_serial_id', $soldRow->id)->latest('id')->first();
            $invoice = $on ? Sale::withoutGlobalScopes()->whereKey($on->sale_id)->value('invoice_no') : null;
            $msg = "IMEI {$imei} ইতিমধ্যে বিক্রি হয়ে গেছে".($invoice ? " (মেমো {$invoice})" : '').' — একই IMEI আবার বিক্রি করা যাবে না। ফেরত/এক্সচেঞ্জ বা স্টক সংশোধনের মাধ্যমে স্টকে ফিরলে তখন আবার বিক্রি করা যাবে।';
        } else {
            $msg = "IMEI {$imei} এই দোকানের এই পণ্যের স্টকে নেই (অন্য শাখা বা অন্য পণ্যের স্টকে আছে) — সঠিক IMEI দিন বা আগে স্টক ট্রান্সফার করুন।";
        }

        throw ValidationException::withMessages(['imei' => $msg]);
    }

    public static function restore(?int $serialId): void
    {
        if ($serialId) {
            ProductSerial::whereKey($serialId)->update(['status' => 'in_stock']);
        }
    }
}
