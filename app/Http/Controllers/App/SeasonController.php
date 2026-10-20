<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleItem;
use App\Models\Season;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SeasonController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        Season::create($data);

        return back()->with('success', 'সিজন যোগ করা হয়েছে।');
    }

    public function update(Request $request, Season $season)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $season->update($data);

        return back()->with('success', 'সিজন হালনাগাদ করা হয়েছে।');
    }

    /** Campaigns under this season are kept, not deleted (nullOnDelete on season_id) — a season is just an organizing label, removing it shouldn't take its campaigns' discount/message setup down with it. */
    public function destroy(Season $season)
    {
        $season->delete();

        return back()->with('success', 'সিজন মুছে ফেলা হয়েছে।');
    }

    /**
     * "সিজন স্টক প্ল্যানার" — Phase 3 feature 2, see docs/FIX_PLAN.md's
     * design note. No new source of truth: the suggestion is simply last
     * year's actual qty sold during this exact same date window (shifted
     * back one year), read straight from the existing sale_items/sales
     * history — same whereHas('sale', ...) pattern Reports::productStats()
     * already uses for every other per-product report. A product
     * discontinued since last year is silently skipped (nothing sensible
     * to reorder).
     */
    public function stockPlan(Season $season)
    {
        $lastYearStart = $season->starts_at?->copy()->subYear();
        $lastYearEnd = $season->expires_at?->copy()->subYear() ?? $lastYearStart;

        $suggestions = [];
        if ($lastYearStart) {
            $sold = SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $lastYearStart)->whereDate('date', '<=', $lastYearEnd))
                ->whereNotNull('product_id')
                ->selectRaw('product_id, SUM(qty) as qty_sold')
                ->groupBy('product_id')
                ->get();

            // withCount('variants') + the filter below: a variant (size/
            // color) product has its own per-variant stock, not the base
            // product's — PurchaseController::markReceived() hard-aborts a
            // purchase for such a product unless a specific variant_id was
            // chosen, which this planner doesn't ask for. Excluding them
            // here is the honest choice over generating a draft order that
            // would fail the moment the owner tries to receive it.
            $products = Product::whereIn('id', $sold->pluck('product_id'))
                ->withCount('variants')
                ->get(['id', 'name', 'emoji', 'stock', 'cost'])
                ->keyBy('id')
                ->filter(fn ($p) => $p->variants_count === 0);

            $suggestions = $sold
                ->filter(fn ($row) => $products->has($row->product_id))
                ->map(function ($row) use ($products) {
                    $product = $products->get($row->product_id);
                    $soldQty = (float) $row->qty_sold;
                    // the gap between what's on hand now and what last year's
                    // same season actually moved — never negative, since
                    // already having enough stock isn't something to order more of
                    $suggestedQty = max(0, round($soldQty - (float) $product->stock, 2));

                    return [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'emoji' => $product->emoji,
                        'current_stock' => (float) $product->stock,
                        'qty_sold_last_year' => $soldQty,
                        'suggested_qty' => $suggestedQty,
                        'cost' => (float) ($product->cost ?? 0),
                    ];
                })
                ->sortByDesc('qty_sold_last_year')
                ->values();
        }

        return Inertia::render('App/Seasons/StockPlan', [
            'season' => $season,
            'lastYearWindow' => $lastYearStart ? ['start' => $lastYearStart->toDateString(), 'end' => $lastYearEnd->toDateString()] : null,
            'suggestions' => $suggestions,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * "সাপ্লায়ার PO ড্রাফটে এক ক্লিকে রূপান্তর" — creates one PENDING
     * Purchase per approved line (the owner already reviewed/adjusted qty
     * in the planner UI before this submits, since a pending Purchase has
     * no edit route of its own once created — only markReceived()). Lands
     * exactly where every other purchase order already lives
     * (Purchases/Index.vue), nothing new to build or learn there.
     */
    public function draftPurchases(Request $request, Season $season)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('shop_id', Tenancy::id())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
        ]);

        $created = DB::transaction(function () use ($data) {
            $count = 0;
            foreach ($data['items'] as $item) {
                // purchases.qty is a plain unsignedInteger (see
                // 2026_07_26_000005_...) — rounds UP, never down, since
                // under-ordering defeats the whole point of a stock
                // suggestion; only matters for a weight-based product
                // whose suggested qty came out fractional.
                $qty = (int) ceil((float) $item['qty']);
                $amount = round($qty * $item['cost'], 2);
                if ($qty <= 0 || $amount <= 0) {
                    continue;
                }
                Purchase::create([
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'memo' => 'সিজন স্টক প্ল্যানার থেকে তৈরি',
                    'amount' => $amount,
                    'method' => 'credit',
                    'status' => 'pending',
                    'pending_details' => [
                        'product_id' => $item['product_id'],
                        'qty' => $qty,
                        'cost' => $item['cost'],
                    ],
                    'product_id' => $item['product_id'],
                    'qty' => $qty,
                    'date' => now()->toDateString(),
                ]);
                $count++;
            }

            return $count;
        });

        return redirect()->route('app.purchases.index')->with('success', "{$created}টা পেন্ডিং পারচেজ অর্ডার তৈরি হয়েছে — Purchases পেজ থেকে মাল আসার পর 'গ্রহণ করুন' চাপুন।");
    }
}
