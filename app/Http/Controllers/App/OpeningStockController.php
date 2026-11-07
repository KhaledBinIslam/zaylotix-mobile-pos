<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\IntakeLedger;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEAMAN Phase 6B — "স্টক বসান": after the import every product sits at
 * stock 0; this is how the owner puts in what he actually has, fast.
 *
 * Grid save (store): one number box per existing variant cell; an empty
 * cell is UNCHANGED, never zero. Everything in ONE transaction with
 * lockForUpdate, every filled cell written to the stock_intakes ledger
 * (source 'opening_stock') with the supplier and the cost actually paid.
 * A missing colour/size can be added straight from the grid — the new
 * variant follows the same rules as anywhere else and gets a printable
 * ZV barcode immediately.
 *
 * Bulk (sheet/upload): a CSV per category to fill in Excel/Sheets.
 * The upload matches rows by the ids the sheet itself carried — a row
 * that doesn't match is listed back, never guessed — and a dry-run
 * previews every change while writing nothing.
 */
class OpeningStockController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'default_cost' => ['nullable', 'numeric', 'min:0'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            // Khaled live: "koto tk pay korche ... koto due ache show
            // hobe" — one choice for the whole grid submission (one
            // supplier, one visit), not per cell — see SupplierPayable.
            'payment_method' => ['nullable', 'in:cash,bank,credit'],
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.variant_id' => ['nullable', 'integer'],
            'cells.*.color' => ['nullable', 'string', 'max:100'],
            'cells.*.size' => ['nullable', 'string', 'max:100'],
            'cells.*.qty' => ['required', 'numeric', 'min:0.001'],
            'cells.*.cost' => ['nullable', 'numeric', 'min:0'],
            'cells.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $supplierId = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id'])?->id : null;
        $defaultCost = isset($data['default_cost']) ? (float) $data['default_cost'] : null;
        // Khaled live: the grid let him price a NEW cell but never an
        // EXISTING one — "oikhane sell price set korte parchina", stock
        // from a supplier at a fresh price kept landing back at the
        // article's old default. Same "default for all cells, per-cell
        // overrides it" shape as cost, just for price.
        $defaultPrice = isset($data['default_price']) ? (float) $data['default_price'] : null;

        $created = 0;

        DB::transaction(function () use ($product, $data, $supplierId, $defaultCost, $defaultPrice, &$created) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->first();
            abort_unless($lockedProduct, 422, 'পণ্যটি ইতিমধ্যে মুছে ফেলা হয়েছে।');

            $totalDelta = 0.0;
            $totalPaid = 0.0;

            foreach ($data['cells'] as $cell) {
                $qty = (float) $cell['qty'];
                $paidCost = isset($cell['cost']) ? (float) $cell['cost'] : $defaultCost;
                $paidPrice = isset($cell['price']) ? (float) $cell['price'] : $defaultPrice;

                $variant = null;
                if (! empty($cell['variant_id'])) {
                    $variant = ProductVariant::whereKey((int) $cell['variant_id'])
                        ->where('product_id', $lockedProduct->id)
                        ->lockForUpdate()->first();
                    abort_unless($variant, 422, 'একটি ভ্যারিয়েন্ট পাওয়া যায়নি — পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
                } elseif (($cell['color'] ?? null) !== null || ($cell['size'] ?? null) !== null) {
                    // a new colour row / size column straight from the grid —
                    // same rules as adding a variant anywhere else; trimmed,
                    // and 'Navy Blue' can never twin 'Navy blue' (INV-1001)
                    $color = trim((string) ($cell['color'] ?? '')) ?: null;
                    $size = trim((string) ($cell['size'] ?? '')) ?: null;
                    abort_if($color === null && $size === null, 422, 'নতুন ঘরের রং/সাইজ ফাঁকা।');

                    abort_if(ProductVariant::cellExists($lockedProduct->id, $color, $size), 422, 'এই ঘরের ভ্যারিয়েন্ট আগেই আছে (বড়/ছোট হাতের তফাত ধরা হয় না) — পেজ রিফ্রেশ করে সেটাতেই স্টক বসান।');

                    $variant = ProductVariant::create([
                        'shop_id' => Tenancy::id(),
                        'product_id' => $lockedProduct->id,
                        'color' => $color,
                        'size' => $size,
                        'stock' => 0,
                        'cost' => $paidCost,
                        'price' => $paidPrice,
                    ]);
                    // printable immediately — same ZV{id} the label page generates
                    $variant->update(['barcode' => 'ZV'.$variant->id]);
                    $created++;
                }

                if ($variant) {
                    // same costing rule as every other stock-in: blend with
                    // the feature, overwrite without; on 0-stock openings
                    // both roads land on the paid cost
                    if ($paidCost !== null) {
                        $oldStock = (float) $variant->stock;
                        $oldCost = (float) ($variant->cost ?? $lockedProduct->cost ?? 0);
                        $usesAverage = Tenancy::shop()?->usesWeightedAverageCosting();
                        $variant->cost = ($usesAverage && $oldStock > 0)
                            ? round((($oldStock * $oldCost) + ($qty * $paidCost)) / ($oldStock + $qty), 2)
                            : $paidCost;
                    }
                    // price is never blended (there's no "average sell
                    // price" concept) — a supplied one just overwrites,
                    // same as everywhere else price is set
                    if ($paidPrice !== null) {
                        $variant->price = $paidPrice;
                    }
                    $variant->applyStockDelta($qty);
                    $variant->save();
                    // Khaled live: the grid picked a supplier for the
                    // ledger but never touched a LINE, so that supplier
                    // was invisible to the filter and the per-lot sticker
                    // printer — "sujon er ki add korchi pacchina". Same
                    // merge-or-create every other stock-in path uses;
                    // brand is never set from this grid (Nil), same as a
                    // cell here never had a brand picker of its own.
                    $lineCost = $paidCost ?? (float) ($variant->cost ?? $lockedProduct->cost ?? 0);
                    if (Tenancy::shop()?->hasFeature('source_lines')) {
                        ProductSource::upsertLine(
                            Tenancy::id(), $lockedProduct->id, $variant->id,
                            $supplierId, null, $qty, $lineCost,
                            $paidPrice, (float) $variant->price
                        );
                    }
                    IntakeLedger::record($lockedProduct, $variant, $qty, $paidCost, 'opening_stock', $supplierId);
                    $totalPaid += $qty * $lineCost;
                } else {
                    // the plain, variantless product: one box, straight on
                    if ($paidPrice !== null) {
                        $lockedProduct->update(['price' => $paidPrice]);
                    }
                    $lineCost = $paidCost ?? (float) ($lockedProduct->cost ?? 0);
                    if (Tenancy::shop()?->hasFeature('source_lines')) {
                        ProductSource::upsertLine(
                            Tenancy::id(), $lockedProduct->id, null,
                            $supplierId, null, $qty, $lineCost,
                            $paidPrice, (float) $lockedProduct->price
                        );
                    }
                    IntakeLedger::record($lockedProduct, null, $qty, $paidCost, 'opening_stock', $supplierId);
                    $totalPaid += $qty * $lineCost;
                }

                $totalDelta += $qty;
            }

            $lockedProduct->applyStockDelta($totalDelta);
            if ($supplierId && ! $lockedProduct->supplier_id) {
                $lockedProduct->update(['supplier_id' => $supplierId]);
            }
            \App\Support\SupplierPayable::apply($supplierId, $data['payment_method'] ?? null, $totalPaid);

            Activity::log('product.openingStock', "'{$lockedProduct->name}'-এ ওপেনিং স্টক বসানো হয়েছে (মোট {$totalDelta})।", $lockedProduct);
        });

        return back()->with('success', $created > 0
            ? "স্টক বসানো হয়েছে — নতুন {$created}টি ভ্যারিয়েন্টও তৈরি হলো (বারকোডসহ)।"
            : 'স্টক বসানো হয়েছে।');
    }

    /** The fill-in-Excel sheet, one category at a time (or the whole shop). Carries ids so the upload never has to guess. */
    public function sheet(Request $request)
    {
        $categoryId = $request->integer('category_id') ?: null;

        $products = Product::with(['variants' => fn ($q) => $q->orderBy('color')->orderBy('size')])
            ->when($categoryId, fn ($q) => $q->whereIn('category_id', ProductCategory::idsIncludingChildren($categoryId)))
            ->orderBy('name')
            ->get(['id', 'name', 'cost', 'stock', 'category_id']);

        $lines = ["product_id,variant_id,product,colour,size,current_stock,new_qty,cost"];
        foreach ($products as $p) {
            if ($p->variants->isEmpty()) {
                $lines[] = $this->csvLine([$p->id, '', $p->name, '', '', (0 + $p->stock), '', $p->cost]);
            } else {
                foreach ($p->variants as $v) {
                    $lines[] = $this->csvLine([$p->id, $v->id, $p->name, $v->color, $v->size, (0 + $v->stock), '', $v->cost ?? $p->cost]);
                }
            }
        }

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="opening-stock-'.($categoryId ?: 'all').'.csv"',
        ]);
    }

    private function csvLine(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) $c;

            return (str_contains($c, ',') || str_contains($c, '"')) ? '"'.str_replace('"', '""', $c).'"' : $c;
        }, $cells));
    }

    /**
     * Upload the filled sheet back. dry_run=1 (the default path the UI
     * takes first) parses and previews every change, writing NOTHING;
     * the confirmed run applies all matched rows in one transaction.
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:4096'],
            'supplier_id' => ['nullable', 'integer'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $supplierId = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id'])?->id : null;
        $dry = (bool) ($data['dry_run'] ?? true);

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $need = ['product_id', 'variant_id', 'new_qty'];
        if (array_diff($need, $header) !== []) {
            return response()->json(['error' => 'শিটের হেডার বদলে গেছে — ডাউনলোড করা শিটেই new_qty বসিয়ে আপলোড করুন।'], 422);
        }

        $changes = [];
        $unmatched = [];
        $lineNo = 1;
        while (($raw = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (trim(implode('', array_map('strval', $raw))) === '') {
                continue;
            }
            $row = array_combine($header, array_pad(array_map(fn ($v) => trim((string) $v), $raw), count($header), ''));

            if (($row['new_qty'] ?? '') === '') {
                continue; // empty = unchanged, exactly like an empty grid cell
            }
            if (! is_numeric($row['new_qty']) || (float) $row['new_qty'] <= 0) {
                $unmatched[] = "লাইন {$lineNo}: new_qty '{$row['new_qty']}' বোঝা যায়নি";

                continue;
            }

            $product = is_numeric($row['product_id'] ?? '') ? Product::find((int) $row['product_id']) : null;
            if (! $product) {
                $unmatched[] = "লাইন {$lineNo}: product_id '{$row['product_id']}' মেলেনি";

                continue;
            }

            $variant = null;
            if (($row['variant_id'] ?? '') !== '') {
                $variant = is_numeric($row['variant_id']) ? ProductVariant::whereKey((int) $row['variant_id'])->where('product_id', $product->id)->first() : null;
                if (! $variant) {
                    $unmatched[] = "লাইন {$lineNo}: variant_id '{$row['variant_id']}' মেলেনি";

                    continue;
                }
            } elseif ($product->variants()->exists()) {
                $unmatched[] = "লাইন {$lineNo}: '{$product->name}'-এর ভ্যারিয়েন্ট আছে, variant_id ফাঁকা রাখা যাবে না";

                continue;
            }

            $cost = ($row['cost'] ?? '') !== '' && is_numeric($row['cost']) ? (float) $row['cost'] : null;

            $changes[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'label' => $product->name.($variant ? ' — '.trim(($variant->color ?? '').' '.($variant->size ?? '')) : ''),
                'current' => (float) ($variant->stock ?? $product->stock),
                'add' => (float) $row['new_qty'],
                'cost' => $cost,
            ];
        }
        fclose($fh);

        if ($dry) {
            return response()->json(['dry_run' => true, 'changes' => $changes, 'unmatched' => $unmatched]);
        }

        DB::transaction(function () use ($changes, $supplierId) {
            $byProduct = collect($changes)->groupBy('product_id');
            foreach ($byProduct as $productId => $rows) {
                $lockedProduct = Product::whereKey($productId)->lockForUpdate()->first();
                if (! $lockedProduct) {
                    continue;
                }
                $totalDelta = 0.0;
                foreach ($rows as $row) {
                    if ($row['variant_id']) {
                        $variant = ProductVariant::whereKey($row['variant_id'])->lockForUpdate()->first();
                        if (! $variant) {
                            continue;
                        }
                        if ($row['cost'] !== null) {
                            $oldStock = (float) $variant->stock;
                            $oldCost = (float) ($variant->cost ?? $lockedProduct->cost ?? 0);
                            $usesAverage = Tenancy::shop()?->usesWeightedAverageCosting();
                            $variant->cost = ($usesAverage && $oldStock > 0)
                                ? round((($oldStock * $oldCost) + ($row['add'] * $row['cost'])) / ($oldStock + $row['add']), 2)
                                : $row['cost'];
                        }
                        $variant->applyStockDelta($row['add']);
                        $variant->save();
                        IntakeLedger::record($lockedProduct, $variant, $row['add'], $row['cost'], 'opening_stock', $supplierId);
                    } else {
                        IntakeLedger::record($lockedProduct, null, $row['add'], $row['cost'], 'opening_stock', $supplierId);
                    }
                    $totalDelta += $row['add'];
                }
                $lockedProduct->applyStockDelta($totalDelta);
            }

            Activity::log('product.openingStock.bulk', 'বাল্ক শিট থেকে ওপেনিং স্টক বসানো হয়েছে ('.count($changes).' টি ঘর)।');
        });

        return response()->json(['dry_run' => false, 'applied' => count($changes), 'unmatched' => $unmatched]);
    }
}
