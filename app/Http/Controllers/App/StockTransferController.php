<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Instant, real-time stock moves between branches of the same business —
 * warehouse->branch, branch->branch, branch->warehouse are all the exact
 * same mechanism (a warehouse is just a normal branch, see the is_warehouse
 * migration's docblock). "Instant" deliberately, per Khaled's own wording
 * ("real time sync hoi moto instant") -- no pending/receiving-confirmation
 * step; the moment a transfer is submitted, both sides' stock update in the
 * same DB transaction.
 *
 * Each branch keeps its own independent Product row (never a shared one —
 * see CatalogSync's docblock for why), so a transfer target product is
 * found by the exact same barcode-then-name matching CatalogSync already
 * uses, and auto-created on the destination branch (stock 0, then
 * incremented) if this is the first time that product has ever reached it —
 * a warehouse legitimately carries things a given branch doesn't stock yet.
 */
class StockTransferController extends Controller
{
    public function index()
    {
        // owner-only, same reasoning as BranchController::switch() — a staff
        // account is always fixed to the one branch it was created under and
        // never sees the branch switcher, so it shouldn't be able to move
        // stock across branches either
        abort_unless(Auth::guard('web')->user()?->role === 'owner', 403);

        $shopId = Tenancy::id();
        $shop = Shop::withoutGlobalScopes()->find($shopId);
        $rootId = $shop->parent_shop_id ?? $shop->id;

        $siblings = Shop::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_shop_id', $rootId))
            ->where('id', '!=', $shopId)
            ->orderBy('id')
            ->get(['id', 'name', 'area', 'is_warehouse']);

        // Only what the transfer form needs to show per-variant stock —
        // never cost/profit, same reasoning as ReturnController::
        // presentProductForPicker (this list already excluded cost before
        // variants were added here, kept that way).
        $products = Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
            ->withCount(['batches', 'serials'])
            ->orderBy('name')
            ->get(['id', 'name', 'emoji', 'barcode', 'stock', 'sold_by_weight'])
            ->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'emoji' => $p->emoji, 'barcode' => $p->barcode,
                'stock' => (float) $p->stock, 'sold_by_weight' => $p->sold_by_weight,
                // batch/serial-tracked products can't transfer yet (see
                // store()) — the form disables/explains instead of letting
                // a cashier hit a confusing server error
                'batch_or_serial_tracked' => $p->batches_count > 0 || $p->serials_count > 0,
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id, 'size' => $v->size, 'color' => $v->color, 'label' => $v->label(), 'stock' => (float) $v->stock, 'barcode' => $v->barcode,
                ]),
            ]);

        return Inertia::render('App/StockTransfers/Index', [
            'siblings' => $siblings,
            'products' => $products,
            'recentTransfers' => StockTransfer::withoutGlobalScopes()
                ->where('from_shop_id', $shopId)->orWhere('to_shop_id', $shopId)
                ->with(['fromShop:id,name', 'toShop:id,name', 'user:id,name'])
                ->latest('id')->limit(30)->get(),
        ]);
    }

    /**
     * `lines` replaces the old single `qty` field — a plain product sends
     * one line with no variant_id (unchanged behavior); a variant product
     * sends one line per size/color actually being moved, each with its own
     * qty, so the form can show (and this can validate against) each
     * variant's own current stock rather than one combined number.
     */
    public function store(Request $request)
    {
        abort_unless(Auth::guard('web')->user()?->role === 'owner', 403);

        $data = $request->validate([
            'to_shop_id' => ['required', 'integer'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $shopId = Tenancy::id();
        $sourceShop = Shop::withoutGlobalScopes()->find($shopId);
        $destShop = Shop::withoutGlobalScopes()->find($data['to_shop_id']);

        abort_if(! $destShop, 404);
        $sourceRoot = $sourceShop->parent_shop_id ?? $sourceShop->id;
        $destRoot = $destShop->parent_shop_id ?? $destShop->id;
        abort_unless($sourceRoot === $destRoot, 403, 'শুধু নিজের ব্যবসার শাখার মধ্যে স্টক পাঠানো যাবে।');
        abort_if($destShop->id === $sourceShop->id, 422, 'নিজের কাছেই স্টক পাঠানো যাবে না।');

        DB::transaction(function () use ($data, $sourceShop, $destShop) {
            $sourceProduct = Product::whereKey($data['product_id'])->lockForUpdate()->first();
            abort_if(! $sourceProduct, 404, 'পণ্যটি পাওয়া যায়নি।');

            // Batch/IMEI-serial transfer isn't built yet — blocking with a
            // clear message here (rather than silently desyncing batch/
            // serial bookkeeping the way variant stock used to) until that's
            // a dedicated piece of work.
            if ($sourceProduct->batches()->exists() || $sourceProduct->serials()->exists()) {
                abort(422, "{$sourceProduct->name} ব্যাচ/সিরিয়াল ট্র্যাক করা পণ্য — এই ধরনের পণ্য এখনো শাখার মধ্যে ট্রান্সফার করা যায় না।");
            }

            $hasVariants = $sourceProduct->variants()->exists();
            $lines = collect($data['lines']);

            if ($hasVariants && $lines->contains(fn ($l) => empty($l['product_variant_id']))) {
                throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর সাইজ/রং আছে — একটা নির্দিষ্ট ভ্যারিয়েন্ট না বেছে ট্রান্সফার করা যাবে না।"]);
            }
            if (! $hasVariants && ($lines->count() > 1 || $lines->contains(fn ($l) => ! empty($l['product_variant_id'])))) {
                throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর কোনো ভ্যারিয়েন্ট নেই।"]);
            }

            if (! $sourceProduct->sold_by_weight && $lines->contains(fn ($l) => floor($l['qty']) != $l['qty'])) {
                abort(422, "{$sourceProduct->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
            }

            // matched exactly like CatalogSync — barcode first, then name —
            // and auto-created on the destination if this is the first time
            $destProduct = Product::withoutGlobalScopes()->where('shop_id', $destShop->id)
                ->where(fn ($q) => filled($sourceProduct->barcode)
                    ? $q->where('barcode', $sourceProduct->barcode)->orWhere('name', $sourceProduct->name)
                    : $q->where('name', $sourceProduct->name))
                ->lockForUpdate()->first();

            if (! $destProduct) {
                $destProduct = Product::create([
                    'shop_id' => $destShop->id,
                    'name' => $sourceProduct->name, 'name_en' => $sourceProduct->name_en,
                    'emoji' => $sourceProduct->emoji, 'barcode' => $sourceProduct->barcode,
                    'cost' => $sourceProduct->cost, 'price' => $sourceProduct->price,
                    'sold_by_weight' => $sourceProduct->sold_by_weight, 'weight_unit' => $sourceProduct->weight_unit,
                    'stock' => 0,
                ]);
            }

            $totalQty = 0.0;
            $lineSummaries = [];

            foreach ($lines as $line) {
                $qty = (float) $line['qty'];
                $totalQty += $qty;

                if (! empty($line['product_variant_id'])) {
                    $sourceVariant = ProductVariant::whereKey($line['product_variant_id'])->lockForUpdate()->first();
                    if (! $sourceVariant || $sourceVariant->product_id !== $sourceProduct->id) {
                        throw ValidationException::withMessages(['lines' => 'এই ভ্যারিয়েন্টটি সঠিক নয়।']);
                    }
                    if ($sourceVariant->stock < $qty) {
                        throw ValidationException::withMessages(['lines' => "পর্যাপ্ত স্টক নেই: {$sourceProduct->name} ({$sourceVariant->label()}) — আছে {$sourceVariant->stock}।"]);
                    }

                    $sourceVariant->decrement('stock', $qty);
                    $sourceProduct->decrement('stock', $qty);

                    // same matching precedence as the plain-product match
                    // above — barcode first (if the variant has one), else
                    // size+color
                    $destVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $destShop->id)->where('product_id', $destProduct->id)
                        ->where(fn ($q) => filled($sourceVariant->barcode)
                            ? $q->where('barcode', $sourceVariant->barcode)->orWhere(fn ($q2) => $q2->where('size', $sourceVariant->size)->where('color', $sourceVariant->color))
                            : $q->where('size', $sourceVariant->size)->where('color', $sourceVariant->color))
                        ->lockForUpdate()->first();

                    if (! $destVariant) {
                        $destVariant = ProductVariant::create([
                            'shop_id' => $destShop->id, 'product_id' => $destProduct->id,
                            'size' => $sourceVariant->size, 'color' => $sourceVariant->color, 'barcode' => $sourceVariant->barcode,
                            'price' => $sourceVariant->price, 'cost' => $sourceVariant->cost,
                            'stock' => 0,
                        ]);
                    }
                    $destVariant->increment('stock', $qty);
                    $destProduct->increment('stock', $qty);

                    $lineSummaries[] = [
                        'from_product_variant_id' => $sourceVariant->id, 'to_product_variant_id' => $destVariant->id,
                        'variant_label' => $sourceVariant->label(), 'qty' => $qty,
                    ];
                } else {
                    if ($sourceProduct->stock < $qty) {
                        throw ValidationException::withMessages(['lines' => "পর্যাপ্ত স্টক নেই: {$sourceProduct->name} (আছে {$sourceProduct->stock})।"]);
                    }
                    $sourceProduct->decrement('stock', $qty);
                    $destProduct->increment('stock', $qty);

                    $lineSummaries[] = ['from_product_variant_id' => null, 'to_product_variant_id' => null, 'variant_label' => null, 'qty' => $qty];
                }
            }

            foreach ($lineSummaries as $summary) {
                StockTransfer::create([
                    'shop_id' => $sourceShop->id,
                    'from_shop_id' => $sourceShop->id,
                    'to_shop_id' => $destShop->id,
                    'from_product_id' => $sourceProduct->id,
                    'to_product_id' => $destProduct->id,
                    'from_product_variant_id' => $summary['from_product_variant_id'],
                    'to_product_variant_id' => $summary['to_product_variant_id'],
                    'product_name' => $sourceProduct->name,
                    'variant_label' => $summary['variant_label'],
                    'qty' => $summary['qty'],
                    'user_id' => Auth::guard('web')->id(),
                ]);
            }

            Activity::log('stockTransfer.create', "{$sourceProduct->name} — {$totalQty} ইউনিট '{$sourceShop->name}' থেকে '{$destShop->name}'-এ পাঠানো হয়েছে।", $sourceProduct, [
                'qty' => $totalQty, 'to_shop' => $destShop->name,
            ]);
        });

        return back()->with('success', 'স্টক পাঠানো হয়েছে।');
    }
}
