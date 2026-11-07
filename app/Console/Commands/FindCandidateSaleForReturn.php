<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only helper for a one-time data fix Khaled asked for (Baby Park:
 * find the real sale that sold P-50002 size 39, to run through the
 * EXISTING exchange engine — ReturnController::process() — rather than a
 * raw update). Writes nothing. Prints candidates for Khaled to confirm the
 * exact invoice before anything is touched, per his own explicit
 * instruction ("if ... not clear from the data, stop and ask me").
 */
class FindCandidateSaleForReturn extends Command
{
    protected $signature = 'returns:find-candidate {--shop=} {--product=} {--size=}';

    protected $description = 'Read-only: list sale_items matching a shop/product/size, with how much is still returnable. Writes nothing.';

    public function handle(): int
    {
        $shopName = $this->option('shop');
        $product = $this->option('product');
        $size = $this->option('size');

        if (! $shopName || ! $product) {
            $this->error('ব্যবহার: php artisan returns:find-candidate --shop="Baby Park" --product="P-50002" --size=39');

            return self::FAILURE;
        }

        $shops = Shop::withoutGlobalScopes()->where('name', 'like', "%{$shopName}%")->get(['id', 'name']);
        if ($shops->isEmpty()) {
            $this->error("'{$shopName}' নামে কোনো শপ পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $rows = SaleItem::withoutGlobalScopes()
            ->whereIn('shop_id', $shops->pluck('id'))
            ->where('product_name', 'like', "%{$product}%")
            ->when($size, fn ($q) => $q->where('variant_label', 'like', "%{$size}%"))
            ->with(['sale:id,shop_id,invoice_no,date,customer_id', 'sale.customer:id,name,phone'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('কিছুই মেলেনি — শপ/প্রোডাক্ট/সাইজের বানান/অংশ আলাদাভাবে ছোট করে আবার চেষ্টা করুন।');

            return self::SUCCESS;
        }

        $this->table(
            ['sale_item_id', 'invoice_no', 'date', 'shop', 'product_name', 'variant_label', 'qty', 'returned_already', 'returnable'],
            $rows->map(function (SaleItem $item) use ($shops) {
                $returned = (float) \App\Models\SalesReturn::withoutGlobalScopes()->where('sale_item_id', $item->id)->sum('qty');

                return [
                    $item->id,
                    $item->sale?->invoice_no,
                    $item->sale?->date,
                    $shops->firstWhere('id', $item->shop_id)?->name,
                    $item->product_name,
                    $item->variant_label,
                    $item->qty,
                    $returned,
                    max(0, (float) $item->qty - $returned),
                ];
            })
        );

        $this->line('কিছুই লেখা হয়নি — এটা শুধু দেখার জন্য। সঠিক invoice_no নিশ্চিত হয়ে জানান।');

        return self::SUCCESS;
    }
}
