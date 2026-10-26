<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\CatalogSync;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk product upload from a spreadsheet — a shop with hundreds of SKUs
 * (a supershop or pharmacy) shouldn't have to type each one in by hand
 * through the mobile Add Product sheet. Matches on barcode within the shop
 * to decide create-vs-update; rows with no barcode are always created new
 * since there's nothing to match against.
 *
 * Variant grouping (added for Baby Park, see docs/BABY_PARK_PLAN.md):
 * same columns, no new ones — ONLY when the shop has the `product_variants`
 * feature does a row with size and/or color filled in behave differently:
 * every row sharing the same name+category groups under ONE Product, and
 * each such row becomes a ProductVariant of it (matched by barcode, else
 * size+color) instead of writing size/color straight onto its own Product
 * row. A shop without the feature sees byte-identical behavior to before
 * this existed — size/color still land on the Product's own columns.
 */
class ProductImportController extends Controller
{
    private const HEADERS = ['name', 'name_en', 'barcode', 'category', 'unit', 'cost', 'price', 'discount_price', 'stock', 'reorder_point', 'size', 'color'];

    public function template(): StreamedResponse
    {
        $hasVariants = Tenancy::shop()?->hasFeature('product_variants');

        return response()->streamDownload(function () use ($hasVariants) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens Bengali cells as UTF-8, not mojibake
            fputcsv($out, self::HEADERS);

            if ($hasVariants) {
                // Same product name+category repeated across rows groups
                // them under one product — each row becomes that size/
                // color's own variant (own barcode, own stock), not a
                // separate product. A row with no size/color still just
                // creates/updates a plain product, exactly like today.
                fputcsv($out, ['কিডস স্নিকার', 'Kids Sneaker', 'SNEAKER-32-BLACK', 'বাচ্চাদের জুতা', 'জোড়া', '500', '900', '', '10', '3', '32', 'কালো']);
                fputcsv($out, ['কিডস স্নিকার', 'Kids Sneaker', 'SNEAKER-33-BLACK', 'বাচ্চাদের জুতা', 'জোড়া', '500', '900', '', '8', '3', '33', 'কালো']);
                fputcsv($out, ['কিডস স্নিকার', 'Kids Sneaker', 'SNEAKER-32-WHITE', 'বাচ্চাদের জুতা', 'জোড়া', '500', '900', '', '6', '3', '32', 'সাদা']);
            } else {
                fputcsv($out, ['চিনি ১কেজি', 'Sugar 1kg', '8901030123456', 'মুদি', 'পিস', '60', '70', '', '100', '10', '', '']);
            }
            fclose($out);
        }, 'product-import-template.csv');
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'ফাইলটি পড়া যায়নি।']);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'ফাইলটি খালি।']);
        }
        // strip a UTF-8 BOM if the first header cell carries one
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $columns = array_map(fn ($h) => strtolower(trim($h)), $header);

        $hasVariants = (bool) Tenancy::shop()?->hasFeature('product_variants');

        $categoryCache = [];
        $unitCache = [];
        // name+category => Product — only used when $hasVariants, so
        // several rows for the same shoe (same name+category, different
        // size/color) group under one Product within this same upload
        // instead of each becoming its own separate Product lookup.
        $productGroupCache = [];
        $added = 0;
        $updated = 0;
        $variantsAdded = 0;
        $variantsUpdated = 0;
        $skipped = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $data = [];
            foreach ($columns as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $name = $data['name'] ?? '';
            $cost = $data['cost'] ?? '';
            $price = $data['price'] ?? '';
            $stock = $data['stock'] ?? '';

            if ($name === '' || $cost === '' || $price === '' || $stock === ''
                || ! is_numeric($cost) || ! is_numeric($price) || ! is_numeric($stock)) {
                $skipped[] = "সারি {$rowNum}: নাম/দাম/স্টক ঠিক নেই";

                continue;
            }

            DB::transaction(function () use ($data, $name, $cost, $price, $stock, $rowNum, $hasVariants, &$categoryCache, &$unitCache, &$productGroupCache, &$added, &$updated, &$variantsAdded, &$variantsUpdated, &$skipped) {
                $categoryId = null;
                if (! empty($data['category'])) {
                    $key = mb_strtolower($data['category']);
                    if (! isset($categoryCache[$key])) {
                        $categoryCache[$key] = ProductCategory::firstOrCreate(
                            ['shop_id' => Tenancy::id(), 'name' => $data['category']],
                            ['name_en' => $data['category'], 'emoji' => '📦']
                        )->id;
                    }
                    $categoryId = $categoryCache[$key];
                }

                $unitId = null;
                if (! empty($data['unit'])) {
                    $key = mb_strtolower($data['unit']);
                    if (! isset($unitCache[$key])) {
                        $unitCache[$key] = Unit::firstOrCreate(
                            ['shop_id' => Tenancy::id(), 'name' => $data['unit']],
                            ['name_en' => $data['unit'], 'code' => null]
                        )->id;
                    }
                    $unitId = $unitCache[$key];
                }

                $barcode = $data['barcode'] ?? '';
                $size = $data['size'] ?: null;
                $color = $data['color'] ?: null;

                if ($hasVariants && ($size !== null || $color !== null)) {
                    $groupKey = mb_strtolower($name).'|'.($categoryId ?? 'null');

                    // Resolve any barcode collision BEFORE touching the
                    // product group at all — a row that's going to be
                    // skipped must never leave behind a freshly-created,
                    // empty parent product (the bug this ordering fixes).
                    $existingVariant = $barcode !== '' ? ProductVariant::where('barcode', $barcode)->with('product')->first() : null;
                    if ($existingVariant) {
                        $ownerGroupKey = mb_strtolower($existingVariant->product->name ?? '').'|'.($existingVariant->product->category_id ?? 'null');
                        if ($ownerGroupKey !== $groupKey) {
                            $skipped[] = "সারি {$rowNum}: বারকোড অন্য পণ্যের ভ্যারিয়েন্টে আগেই ব্যবহৃত";

                            return;
                        }
                    }

                    if (isset($productGroupCache[$groupKey])) {
                        $product = $productGroupCache[$groupKey];
                    } else {
                        // Only match an EXISTING product that already has
                        // at least one variant — a legacy plain product
                        // with its own unallocated stock needs the
                        // explicit "write off the rest?" confirmation
                        // the manual grid asks for (see
                        // ProductVariantController::reconcilePreExistingStock()),
                        // which an unattended batch import can't ask. A
                        // name+category match with no variants yet just
                        // becomes a brand new, separate product instead —
                        // safe, never silently drops stock.
                        $product = $existingVariant?->product
                            ?? Product::where('name', $name)->where('category_id', $categoryId)->whereHas('variants')->first();

                        if (! $product) {
                            $product = Product::create([
                                'name' => $name,
                                'name_en' => $data['name_en'] ?: $name,
                                'category_id' => $categoryId,
                                'unit_id' => $unitId,
                                'cost' => (float) $cost,
                                'price' => (float) $price,
                                'discount_price' => ($data['discount_price'] ?? '') !== '' ? (float) $data['discount_price'] : null,
                                'stock' => 0,
                                'emoji' => '📦',
                            ]);
                            $added++;
                        }
                        $productGroupCache[$groupKey] = $product;
                    }

                    if (! $existingVariant) {
                        $existingVariant = ProductVariant::where('product_id', $product->id)->where('size', $size)->where('color', $color)->first();
                    }

                    $newStock = (int) $stock;
                    $reorderPoint = ($data['reorder_point'] ?? '') !== '' ? (int) $data['reorder_point'] : null;

                    if ($existingVariant) {
                        $existingVariant->update([
                            'barcode' => $barcode ?: $existingVariant->barcode,
                            'reorder_point' => $reorderPoint ?? $existingVariant->reorder_point,
                        ]);
                        $delta = $newStock - (float) $existingVariant->stock;
                        $existingVariant->applyStockDelta($delta);
                        $product->applyStockDelta($delta);
                        $variantsUpdated++;
                    } else {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'size' => $size,
                            'color' => $color,
                            'barcode' => $barcode ?: null,
                            'stock' => $newStock,
                            'reorder_point' => $reorderPoint,
                        ]);
                        $product->applyStockDelta($newStock);
                        $variantsAdded++;
                    }

                    return;
                }

                $existing = $barcode !== '' ? Product::where('barcode', $barcode)->first() : null;

                $fields = [
                    'name' => $name,
                    'name_en' => $data['name_en'] ?: $name,
                    'barcode' => $barcode ?: null,
                    'category_id' => $categoryId,
                    'unit_id' => $unitId,
                    'cost' => (float) $cost,
                    'price' => (float) $price,
                    'discount_price' => ($data['discount_price'] ?? '') !== '' ? (float) $data['discount_price'] : null,
                    'stock' => (int) $stock,
                    'reorder_point' => ($data['reorder_point'] ?? '') !== '' ? (int) $data['reorder_point'] : null,
                    'size' => $size,
                    'color' => $color,
                ];

                if ($existing) {
                    // a variant product's stock is a live-maintained sum of
                    // its variants — same invariant as the manual edit form,
                    // never let a bulk import drift it away from that sum
                    if ($existing->variants()->exists()) {
                        unset($fields['stock']);
                    }

                    // Routed through applyStockDelta(), not a plain column
                    // overwrite — same reasoning as ProductController::update()
                    // (see docs/FIX_PLAN.md's Phase 38 design note).
                    if (array_key_exists('stock', $fields)) {
                        $newStock = $fields['stock'];
                        unset($fields['stock']);
                        $existing->update($fields);
                        $existing->applyStockDelta((float) $newStock - (float) $existing->stock);
                    } else {
                        $existing->update($fields);
                    }
                    $updated++;
                } else {
                    Product::create($fields + ['emoji' => '📦']);
                    $added++;
                }
            });
        }

        fclose($handle);

        // Bypasses ProductController::store()/update()'s real-time
        // shared_catalog hook entirely (this writes Product/ProductVariant
        // rows directly) — a bulk import is exactly the kind of one-shot
        // owner action BranchController::syncCatalog()'s manual button
        // already covers, so just run the same sync once at the end
        // instead of per-row, same gating (main shop, has real branches,
        // feature on) as that button.
        $shop = Tenancy::shop();
        if (($added || $updated || $variantsAdded || $variantsUpdated) && $shop && ! $shop->parent_shop_id && $shop->hasFeature('shared_catalog')) {
            $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $shop->id)->get();
            foreach ($branches as $branch) {
                CatalogSync::syncToBranch($shop, $branch);
            }
        }

        $summary = "{$added}টি নতুন পণ্য যোগ হয়েছে, {$updated}টি হালনাগাদ হয়েছে।";
        if ($variantsAdded || $variantsUpdated) {
            $summary .= " {$variantsAdded}টি নতুন ভ্যারিয়েন্ট, {$variantsUpdated}টি হালনাগাদ হয়েছে।";
        }
        if ($skipped) {
            $shown = array_slice($skipped, 0, 5);
            $summary .= ' বাদ পড়েছে '.count($skipped).'টি: '.implode('; ', $shown);
            if (count($skipped) > 5) {
                $summary .= ' ...';
            }
        }

        return back()->with($added + $updated + $variantsAdded + $variantsUpdated > 0 ? 'success' : 'error', $summary);
    }
}
