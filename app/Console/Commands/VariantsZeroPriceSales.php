<?php

namespace App\Console\Commands;

use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only: finds every sale_item that sold a variant at price ৳0 — the
 * question Khaled asked directly after finding the POS variant picker could
 * DISPLAY ৳0 for a variant with no price override. Checkout itself has
 * never accepted a client-sent price (see CheckoutRequest — no items.*.price
 * rule exists at all; PosController::performCheckout always computes
 * $variant->effectivePrice() server-side), so this is expected to come back
 * empty — but that's a claim worth proving against real data, not assuming.
 * Never writes anything.
 */
class VariantsZeroPriceSales extends Command
{
    protected $signature = 'variants:zero-price-sales';

    protected $description = 'Read-only: lists every sale_item that sold a variant-having line at price ৳0.';

    public function handle(): int
    {
        $rows = SaleItem::withoutGlobalScopes()
            ->whereNotNull('product_variant_id')
            ->where('price', 0)
            ->with([
                // Sale/ProductVariant carry their own BelongsToTenant scope
                // too — without bypassing it here, every row's sale/variant
                // relation silently comes back null outside a real tenant
                // request (exactly like this command's own context).
                'sale' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'shop_id', 'invoice_no', 'date'),
                'variant' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'size', 'color'),
            ])
            ->get();

        if ($rows->isEmpty()) {
            $this->info('কোনো ৳0 দামে বিক্রি হওয়া ভ্যারিয়েন্ট পাওয়া যায়নি।');

            return self::SUCCESS;
        }

        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $rows->pluck('sale.shop_id')->filter()->unique())->pluck('name', 'id');

        $table = $rows->map(fn (SaleItem $i) => [
            $shopNames->get($i->sale?->shop_id, "shop#{$i->shop_id}"),
            $i->sale?->invoice_no ?? '—',
            $i->sale?->date?->toDateString() ?? '—',
            $i->product_name,
            $i->variant_label ?? ($i->variant ? collect([$i->variant->size, $i->variant->color])->filter()->implode(', ') : '—'),
            (float) $i->qty,
        ]);

        $this->table(['Shop', 'Invoice', 'Date', 'Product', 'Variant', 'Qty'], $table->all());
        $this->newLine();
        $this->info($rows->count().'টা লাইন ৳0 দামে বিক্রি হয়েছে। এই কমান্ড কিছু পরিবর্তন করেনি — শুধু রিপোর্ট দেখিয়েছে।');

        return self::SUCCESS;
    }
}
