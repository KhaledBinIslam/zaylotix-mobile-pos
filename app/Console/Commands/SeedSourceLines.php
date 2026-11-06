<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Part 1 (source_lines) — backfills the ONE implicit line every existing
 * product/variant already has: its current supplier, brand, cost, price,
 * stock and barcode, copied as-is (never regenerated). Zero-breakage by
 * construction — after this runs, sum(lines.stock) === variant.stock for
 * every row it touched, so nothing downstream (POS, reports, reorder
 * alerts) sees any difference; the lines only become visible once the
 * shop's own UI starts showing them (feature source_lines).
 *
 * Idempotent: a variant/product that already has at least one line
 * (soft-deleted ones don't count — see cellExists-style "active rows
 * only" reasoning) is skipped untouched. Safe to re-run after new
 * products/variants are added — it only ever backfills what's missing.
 */
class SeedSourceLines extends Command
{
    protected $signature = 'products:seed-source-lines {--shop=}';

    protected $description = 'Backfills one product_sources line per existing product/variant (copies its current supplier/brand/cost/price/stock/barcode). Idempotent.';

    public function handle(): int
    {
        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : Shop::withoutGlobalScopes()->whereHas('features', fn ($q) => $q->where('key', 'source_lines'))->pluck('id')->all();

        $created = 0;
        $skipped = 0;

        foreach ($shopIds as $shopId) {
            DB::transaction(function () use ($shopId, &$created, &$skipped) {
                $variantProductIds = ProductVariant::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->pluck('product_id')->unique();

                // variant-bearing products: one line PER VARIANT
                foreach (ProductVariant::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->get() as $variant) {
                    $hasLine = ProductSource::withoutGlobalScopes()->where('product_variant_id', $variant->id)->whereNull('deleted_at')->exists();
                    if ($hasLine) {
                        $skipped++;

                        continue;
                    }
                    $product = Product::withoutGlobalScopes()->find($variant->product_id);
                    ProductSource::create([
                        'shop_id' => $shopId,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        // product_variants has no supplier_id column of its
                        // own (only products does) — the backfilled line
                        // inherits the product's supplier; brand DOES have
                        // its own per-variant column (the helmet case)
                        'supplier_id' => $product?->supplier_id,
                        'brand_id' => $variant->brand_id ?? $product?->brand_id,
                        'cost' => $variant->cost ?? $product?->cost ?? 0,
                        'price' => $variant->price ?? $product?->price ?? 0,
                        'stock' => $variant->stock,
                        // the EXISTING barcode is reused, not regenerated —
                        // nothing already printed ever needs reprinting
                        'barcode' => $variant->barcode,
                    ]);
                    $created++;
                }

                // plain (variantless) products: one line for the product itself
                foreach (Product::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->whereNotIn('id', $variantProductIds)->get() as $product) {
                    $hasLine = ProductSource::withoutGlobalScopes()->where('product_id', $product->id)->whereNull('product_variant_id')->whereNull('deleted_at')->exists();
                    if ($hasLine) {
                        $skipped++;

                        continue;
                    }
                    ProductSource::create([
                        'shop_id' => $shopId,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'supplier_id' => $product->supplier_id,
                        'brand_id' => $product->brand_id,
                        'cost' => $product->cost,
                        'price' => $product->price,
                        'stock' => $product->stock,
                        'barcode' => $product->barcode,
                    ]);
                    $created++;
                }
            });
        }

        $this->info("নতুন লাইন তৈরি হলো: {$created}টা, আগে থেকেই ছিল: {$skipped}টা।");

        return self::SUCCESS;
    }
}
