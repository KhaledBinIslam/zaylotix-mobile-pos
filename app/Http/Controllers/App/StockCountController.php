<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
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
                // a piece-counted product's recount is always a whole
                // number, even though the column itself now accepts
                // decimals for weighed products — round away any stray
                // fractional entry rather than silently accepting it
                $counted = $product?->sold_by_weight ? (float) $row['counted'] : round((float) $row['counted']);
                if (! $product || (float) $product->stock === $counted) {
                    continue;
                }

                // a variant product's `stock` is a live-maintained sum of
                // its variants — overwriting it here with a hand-counted
                // number would desync it from its parts permanently, with
                // nothing to ever resync it. Skipped, not rejected outright,
                // since one stray variant product in a large recount batch
                // shouldn't block reconciling everything else in it.
                if ($product->variants_count > 0) {
                    continue;
                }

                $changes[] = ['product_id' => $product->id, 'from' => (float) $product->stock, 'to' => $counted];
                $product->update(['stock' => $counted]);
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
