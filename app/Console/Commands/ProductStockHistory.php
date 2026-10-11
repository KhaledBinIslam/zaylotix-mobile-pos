<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\StockTransfer;
use Illuminate\Console\Command;

/**
 * Read-only: everything that could explain a product.stock vs
 * SUM(variants.stock) mismatch found by variants:stock-mismatch — its
 * variants, every stock transfer in/out of it, every purchase/stock-in
 * recorded against it, and the shop's own activity log entries naming it.
 * Khaled decides what (if anything) to reconcile; this never writes
 * anything.
 */
class ProductStockHistory extends Command
{
    protected $signature = 'product:stock-history {product_id}';

    protected $description = 'Read-only: shows a product\'s variants, transfer history, purchases, and activity log — to help explain a stock mismatch.';

    public function handle(): int
    {
        $productId = (int) $this->argument('product_id');
        $product = Product::withoutGlobalScopes()->find($productId);

        if (! $product) {
            $this->error("পণ্য #{$productId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($product->shop_id);
        $shopLabel = $shop?->name ?? "shop#{$product->shop_id}";
        $this->info("--- পণ্য: {$product->name} (#{$product->id}), দোকান: {$shopLabel} ---");
        $this->line("বর্তমান products.stock: {$product->stock}");
        $this->newLine();

        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $productId)->get();
        $this->table(
            ['Variant ID', 'Size', 'Color', 'Barcode', 'Stock'],
            $variants->map(fn (ProductVariant $v) => [$v->id, $v->size, $v->color, $v->barcode, $v->stock])->all()
        );
        $this->line('variant stock এর যোগফল: '.$variants->sum('stock'));
        $this->newLine();

        $transfers = StockTransfer::withoutGlobalScopes()
            ->where('from_product_id', $productId)->orWhere('to_product_id', $productId)
            ->with(['fromShop:id,name', 'toShop:id,name', 'fromVariant:id,size,color', 'toVariant:id,size,color', 'user:id,name'])
            ->orderBy('id')->get();

        if ($transfers->isEmpty()) {
            $this->info('এই পণ্যের কোনো stock transfer রেকর্ড নেই।');
        } else {
            $this->info('--- Stock Transfer ইতিহাস ---');
            $this->table(
                ['ID', 'তারিখ', 'কোথা থেকে', 'কোথায়', 'Variant', 'Qty', 'কে করেছে'],
                $transfers->map(fn (StockTransfer $t) => [
                    $t->id, $t->created_at->toDateTimeString(),
                    $t->fromShop?->name, $t->toShop?->name,
                    $t->variant_label ?? '(সাধারণ, কোনো variant নয়)',
                    $t->qty, $t->user?->name,
                ])->all()
            );
        }
        $this->newLine();

        $purchases = Purchase::withoutGlobalScopes()->where('product_id', $productId)->orderBy('id')->get();
        if ($purchases->isEmpty()) {
            $this->info('এই পণ্যের কোনো purchase/stock-in রেকর্ড নেই।');
        } else {
            $this->info('--- Purchase/Stock-in ইতিহাস ---');
            $purchaseVariants = ProductVariant::withoutGlobalScopes()->whereIn('id', $purchases->pluck('product_variant_id')->filter())->get()->keyBy('id');
            $this->table(
                ['ID', 'তারিখ', 'Variant', 'Qty', 'দাম'],
                $purchases->map(function (Purchase $p) use ($purchaseVariants) {
                    $v = $p->product_variant_id ? $purchaseVariants->get($p->product_variant_id) : null;
                    $variantLabel = $p->product_variant_id ? ($v ? collect([$v->size, $v->color])->filter()->implode(', ') : "variant#{$p->product_variant_id} (মুছে ফেলা হয়েছে)") : '⚠️ কোনো variant বলা হয়নি';

                    return [$p->id, $p->date?->toDateString(), $variantLabel, $p->qty, $p->amount];
                })->all()
            );
        }
        $this->newLine();

        $logs = ActivityLog::withoutGlobalScopes()
            ->where('subject_type', Product::class)->where('subject_id', $productId)
            ->orderBy('id')->get();
        if ($logs->isEmpty()) {
            $this->info('এই পণ্যের নামে কোনো activity log নেই।');
        } else {
            $this->info('--- Activity Log ---');
            $this->table(
                ['তারিখ', 'Action', 'বিবরণ'],
                $logs->map(fn (ActivityLog $l) => [$l->created_at->toDateTimeString(), $l->action, $l->description])->all()
            );
        }

        $this->newLine();
        $this->info('এই কমান্ড শুধু রিপোর্ট দেখিয়েছে — কিছু পরিবর্তন করেনি।');

        return self::SUCCESS;
    }
}
