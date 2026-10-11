<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time reconciliation tool for a pre-existing products.stock vs
 * SUM(variants.stock) mismatch (found by variants:stock-mismatch) — e.g.
 * Salenoir's RP-Cyan-01 (#347), where 12 units were never attributed to
 * any size. Khaled gives the REAL, physically-counted stock per size;
 * this only ever touches each named variant's own `stock` column, never
 * products.stock itself (which this command treats as already correct —
 * that's exactly why it requires the new numbers to sum to it, not just
 * to each other).
 *
 * Usage: php artisan variants:set-stock 347 40=15 41=13 42=12
 * Shows a preview first; nothing is written until --confirm is also passed.
 */
class VariantsSetStock extends Command
{
    protected $signature = 'variants:set-stock {product_id} {assignments*} {--confirm}';

    protected $description = 'One-time: sets each named variant\'s stock to a given value (label=qty). Shows a preview only, unless --confirm is passed. Requires every existing variant to be named and the total to equal products.stock.';

    public function handle(): int
    {
        $productId = (int) $this->argument('product_id');
        $product = Product::withoutGlobalScopes()->find($productId);

        if (! $product) {
            $this->error("পণ্য #{$productId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $productId)->get()->keyBy(fn (ProductVariant $v) => $v->label());

        if ($variants->isEmpty()) {
            $this->error("পণ্য '{$product->name}'-এর কোনো ভ্যারিয়েন্ট নেই।");

            return self::FAILURE;
        }

        $assignments = [];
        foreach ($this->argument('assignments') as $raw) {
            if (! str_contains($raw, '=')) {
                $this->error("ভুল ফরম্যাট: '{$raw}' — 'label=qty' আকারে দিন, যেমন 40=15");

                return self::FAILURE;
            }
            [$label, $qty] = explode('=', $raw, 2);
            $assignments[trim($label)] = $qty;
        }

        // every current variant must be named exactly once — a partial list
        // would leave an unmentioned variant's own correctness unverified,
        // silently assumed right when nobody actually confirmed that
        $givenLabels = array_keys($assignments);
        $existingLabels = $variants->keys()->all();
        sort($givenLabels);
        sort($existingLabels);
        if ($givenLabels !== $existingLabels) {
            $this->error('প্রতিটা বর্তমান ভ্যারিয়েন্ট ঠিক একবার করে দিতে হবে।');
            $this->line('বর্তমান ভ্যারিয়েন্ট: '.implode(', ', $existingLabels));
            $this->line('আপনি দিয়েছেন: '.implode(', ', $givenLabels));

            return self::FAILURE;
        }

        $newTotal = 0.0;
        $rows = [];
        foreach ($assignments as $label => $qty) {
            if (! is_numeric($qty) || (float) $qty < 0) {
                $this->error("'{$label}'-এর পরিমাণ ({$qty}) সঠিক সংখ্যা না।");

                return self::FAILURE;
            }
            $variant = $variants->get($label);
            $newQty = (float) $qty;
            $newTotal += $newQty;
            $rows[] = [$variant->id, $label, $variant->stock, $newQty, $newQty - $variant->stock];
        }

        $productStock = (float) $product->stock;
        $shopName = Shop::withoutGlobalScopes()->find($product->shop_id)?->name ?? "shop#{$product->shop_id}";

        $this->info("--- প্রিভিউ: '{$product->name}' (দোকান: {$shopName}) ---");
        $this->table(['Variant ID', 'Label', 'বর্তমান স্টক', 'নতুন স্টক', 'পার্থক্য'], $rows);
        $this->line("products.stock (অপরিবর্তিত থাকবে): {$productStock}");
        $this->line("নতুন মোট (সব variant-এর যোগফল): {$newTotal}");

        if (abs($newTotal - $productStock) > 0.001) {
            $this->error("যোগফল ({$newTotal}) products.stock ({$productStock})-এর সাথে মিলছে না — কিছুই লেখা হয়নি।");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে একই কমান্ডে --confirm যোগ করে আবার চালান।');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $product, $productStock, $newTotal) {
            foreach ($rows as [$variantId, $label, $oldQty, $newQty, $diff]) {
                ProductVariant::withoutGlobalScopes()->whereKey($variantId)->update(['stock' => $newQty]);
            }

            ActivityLog::create([
                'shop_id' => $product->shop_id,
                'user_id' => null,
                'action' => 'product.variant.setStockReconciliation',
                'subject_type' => Product::class,
                'subject_id' => $product->id,
                'description' => "'{$product->name}'-এর variant স্টক ম্যানুয়ালি মিলিয়ে দেওয়া হয়েছে (php artisan variants:set-stock) — products.stock ({$productStock}) অপরিবর্তিত, variant-গুলোর যোগফল এখন {$newTotal}।",
                'meta' => ['rows' => $rows],
                'created_at' => now(),
            ]);
        });

        $this->newLine();
        $this->info('লেখা হয়ে গেছে। variants:stock-mismatch আবার চালিয়ে নিশ্চিত হতে পারেন।');

        return self::SUCCESS;
    }
}
