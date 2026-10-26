<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockCountController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'counts' => ['required', 'array'],
            'counts.*.product_id' => ['required', 'exists:products,id'],
            'counts.*.product_variant_id' => ['nullable', 'integer'],
            'counts.*.counted' => ['required', 'numeric', 'min:0'],
        ]);

        // sorted by id before locking anything — two overlapping recount
        // submissions that each touch several of the same products in a
        // different (UI entry) order would otherwise be a textbook InnoDB
        // deadlock (A locks #1 then waits on #2 while B locks #2 then waits
        // on #1); a single fixed lock order across every submission makes
        // that impossible.
        $counts = collect($data['counts'])->sortBy('product_id')->values()->all();

        $changes = DB::transaction(function () use ($counts) {
            $changes = [];

            foreach ($counts as $row) {
                $product = Product::withCount('variants')->whereKey($row['product_id'])->lockForUpdate()->first();
                if (! $product) {
                    continue;
                }

                // a piece-counted product's recount is always a whole
                // number, even though the column itself now accepts
                // decimals for weighed products — round away any stray
                // fractional entry rather than silently accepting it
                $counted = $product->sold_by_weight ? (float) $row['counted'] : round((float) $row['counted']);

                // a variant product's own `stock` is a live-maintained sum
                // of its variants — recounting a SPECIFIC variant (named by
                // product_variant_id) moves both that variant's stock and
                // the product total together, same as Damage/Return;
                // recounting the product itself isn't offered in the UI for
                // a variant product, and is skipped here if it somehow
                // arrives anyway, for the same desync reason as before.
                if (! empty($row['product_variant_id'])) {
                    $variant = ProductVariant::whereKey($row['product_variant_id'])->lockForUpdate()->first();
                    if (! $variant || $variant->product_id !== $product->id) {
                        continue;
                    }
                    if ((float) $variant->stock === $counted) {
                        continue;
                    }

                    $delta = $counted - (float) $variant->stock;
                    $changes[] = ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'from' => (float) $variant->stock, 'to' => $counted];
                    $variant->applyStockDelta($delta);
                    $product->applyStockDelta($delta);

                    continue;
                }

                if ($product->variants_count > 0 || (float) $product->stock === $counted) {
                    continue;
                }

                $delta = $counted - (float) $product->stock;
                $changes[] = ['product_id' => $product->id, 'from' => (float) $product->stock, 'to' => $counted];
                $product->applyStockDelta($delta);
            }

            StockCount::create([
                'date' => now()->toDateString(),
                'changed' => count($changes),
                'changes' => $changes,
            ]);

            return $changes;
        });

        return back()->with('success', count($changes).' product(s) reconciled.');
    }
}
