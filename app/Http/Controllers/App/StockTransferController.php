<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\BatchStock;
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
        // variants were added here, kept that way). available_serials is
        // the same "never cost" rule — just id/imei/warranty, enough to let
        // the owner pick which physical unit is moving (see store()).
        $products = Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
            ->with(['serials' => fn ($q) => $q->available()->orderBy('id')])
            ->withCount(['batches', 'serials'])
            ->orderBy('name')
            ->get(['id', 'name', 'emoji', 'barcode', 'stock', 'sold_by_weight'])
            ->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'emoji' => $p->emoji, 'barcode' => $p->barcode,
                'stock' => (float) $p->stock, 'sold_by_weight' => $p->sold_by_weight,
                // a serial-tracked product is moved by picking specific
                // IMEIs (see available_serials), a batch-tracked one by a
                // plain qty — FEFO-split transparently on the backend
                // (BatchStock::transferBetweenShops) — the form branches on
                // these instead of blocking either kind outright
                'is_batch_tracked' => $p->batches_count > 0,
                'is_serial_tracked' => $p->serials_count > 0,
                'available_serials' => $p->serials->map(fn (ProductSerial $s) => [
                    'id' => $s->id, 'imei' => $s->imei, 'warranty_expiry' => $s->warranty_expiry,
                ]),
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id, 'size' => $v->size, 'color' => $v->color, 'label' => $v->label(), 'stock' => (float) $v->stock, 'barcode' => $v->barcode,
                ]),
            ]);

        return Inertia::render('App/StockTransfers/Index', [
            'siblings' => $siblings,
            'products' => $products,
            'currentShopId' => $shopId,
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
            // qty drives a plain/variant/batch-tracked line; serial_ids
            // drives a serial-tracked one instead (see store()'s branching)
            // — never both required at once, so each stays nullable here
            // and the real "did you give me enough to work with" check
            // happens per-kind below.
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.001'],
            'lines.*.serial_ids' => ['nullable', 'array'],
            'lines.*.serial_ids.*' => ['integer'],
        ]);

        $shopId = Tenancy::id();
        $sourceShop = Shop::withoutGlobalScopes()->find($shopId);
        $destShop = Shop::withoutGlobalScopes()->find($data['to_shop_id']);

        abort_if(! $destShop, 404);
        $sourceRoot = $sourceShop->parent_shop_id ?? $sourceShop->id;
        $destRoot = $destShop->parent_shop_id ?? $destShop->id;
        abort_unless($sourceRoot === $destRoot, 403, 'শুধু নিজের ব্যবসার শাখার মধ্যে স্টক পাঠানো যাবে।');
        abort_if($destShop->id === $sourceShop->id, 422, 'নিজের কাছেই স্টক পাঠানো যাবে না।');

        // see docs/FIX_PLAN.md's "ব্রাঞ্চ ট্রান্সফার accept/reject" design
        // note — opt-in per business (checked on the root shop, same
        // convention as shared_catalog), off by default so every shop
        // keeps today's exact instant behavior unless it turns this on.
        $rootShop = $sourceShop->parent_shop_id ? Shop::withoutGlobalScopes()->find($sourceRoot) : $sourceShop;
        $requiresConfirmation = (bool) $rootShop?->hasFeature('transfer_confirmation');

        DB::transaction(function () use ($data, $sourceShop, $destShop, $requiresConfirmation) {
            $sourceProduct = Product::whereKey($data['product_id'])->lockForUpdate()->first();
            abort_if(! $sourceProduct, 404, 'পণ্যটি পাওয়া যায়নি।');

            // A serial-tracked product is identified by its specific IMEIs
            // (see the serial branch below), never by a plain qty — a
            // product that happens to carry both serials and batches (not
            // a real combination in practice; pharmacy uses batches,
            // electronics uses serials) is treated as serial-tracked, since
            // "which physical unit" is the more defining identity of the two.
            $isSerialTracked = $sourceProduct->serials()->exists();
            $isBatchTracked = ! $isSerialTracked && $sourceProduct->batches()->exists();
            $hasVariants = $sourceProduct->variants()->exists();
            $lines = collect($data['lines']);

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

            if ($isSerialTracked) {
                $serialIds = $lines->flatMap(fn ($l) => $l['serial_ids'] ?? [])->unique()->values();
                if ($serialIds->isEmpty()) {
                    throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর জন্য কমপক্ষে একটি IMEI/সিরিয়াল বাছাই করুন।"]);
                }

                // available() + lockForUpdate() so a serial already sold
                // (or picked by a second, concurrent transfer) can never be
                // moved twice — the count mismatch below catches both cases
                // with one clear message rather than silently moving fewer
                // units than the owner asked for.
                $serials = ProductSerial::where('product_id', $sourceProduct->id)
                    ->available()->whereIn('id', $serialIds)->lockForUpdate()->get();
                if ($serials->count() !== $serialIds->count()) {
                    throw ValidationException::withMessages(['lines' => 'একটি বা একাধিক IMEI পাওয়া যায়নি বা ইতিমধ্যে বিক্রি/ট্রান্সফার হয়ে গেছে — পেজ রিলোড করে আবার চেষ্টা করুন।']);
                }

                foreach ($serials as $serial) {
                    // the unit itself moves branch — same record, same IMEI,
                    // same warranty/cost history, just now owned by the
                    // destination product/shop (see BelongsToTenant: an
                    // explicit shop_id on an update is never overwritten,
                    // that auto-stamp only fires on create)
                    $serial->update(['shop_id' => $destShop->id, 'product_id' => $destProduct->id]);
                }

                $movedQty = $serials->count();
                $sourceProduct->decrement('stock', $movedQty);
                $destProduct->increment('stock', $movedQty);

                StockTransfer::create([
                    'shop_id' => $sourceShop->id,
                    'from_shop_id' => $sourceShop->id,
                    'to_shop_id' => $destShop->id,
                    'from_product_id' => $sourceProduct->id,
                    'to_product_id' => $destProduct->id,
                    'from_product_variant_id' => null,
                    'to_product_variant_id' => null,
                    'product_name' => $sourceProduct->name,
                    'variant_label' => null,
                    'qty' => $movedQty,
                    'user_id' => Auth::guard('web')->id(),
                ]);

                Activity::log('stockTransfer.create', "{$sourceProduct->name} — {$movedQty} IMEI '{$sourceShop->name}' থেকে '{$destShop->name}'-এ পাঠানো হয়েছে।", $sourceProduct, [
                    'qty' => $movedQty, 'to_shop' => $destShop->name,
                ]);

                return;
            }

            if ($hasVariants && $lines->contains(fn ($l) => empty($l['product_variant_id']))) {
                throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর সাইজ/রং আছে — একটা নির্দিষ্ট ভ্যারিয়েন্ট না বেছে ট্রান্সফার করা যাবে না।"]);
            }
            if (! $hasVariants && ($lines->count() > 1 || $lines->contains(fn ($l) => ! empty($l['product_variant_id'])))) {
                throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর কোনো ভ্যারিয়েন্ট নেই।"]);
            }

            if ($lines->contains(fn ($l) => empty($l['qty']))) {
                throw ValidationException::withMessages(['lines' => "{$sourceProduct->name}-এর পরিমাণ লিখুন।"]);
            }
            if (! $sourceProduct->sold_by_weight && $lines->contains(fn ($l) => floor($l['qty']) != $l['qty'])) {
                abort(422, "{$sourceProduct->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
            }

            $totalQty = 0.0;
            $lineSummaries = [];
            // batch-tracked always stays instant regardless of the feature
            // (see docs/FIX_PLAN.md's design note) — BatchStock::
            // transferBetweenShops() below already moves the actual batch
            // rows at store() time, so deferring the destination's plain
            // stock increment alone would desync it from its own batches
            $deferDestination = $requiresConfirmation && ! $isBatchTracked;

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
                    if (! $deferDestination) {
                        $destVariant->increment('stock', $qty);
                        $destProduct->increment('stock', $qty);
                    }

                    $lineSummaries[] = [
                        'from_product_variant_id' => $sourceVariant->id, 'to_product_variant_id' => $destVariant->id,
                        'variant_label' => $sourceVariant->label(), 'qty' => $qty,
                    ];
                } else {
                    if ($sourceProduct->stock < $qty) {
                        throw ValidationException::withMessages(['lines' => "পর্যাপ্ত স্টক নেই: {$sourceProduct->name} (আছে {$sourceProduct->stock})।"]);
                    }
                    $sourceProduct->decrement('stock', $qty);
                    if (! $deferDestination) {
                        $destProduct->increment('stock', $qty);
                    }
                    if ($isBatchTracked) {
                        BatchStock::transferBetweenShops($sourceProduct, $destProduct, $qty);
                    }

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
                    'status' => $deferDestination ? 'pending' : 'completed',
                    'user_id' => Auth::guard('web')->id(),
                ]);
            }

            Activity::log('stockTransfer.create', "{$sourceProduct->name} — {$totalQty} ইউনিট '{$sourceShop->name}' থেকে '{$destShop->name}'-এ পাঠানো হয়েছে।", $sourceProduct, [
                'qty' => $totalQty, 'to_shop' => $destShop->name,
            ]);
        });

        return back()->with('success', 'স্টক পাঠানো হয়েছে।');
    }

    /**
     * Only the DESTINATION branch's own owner can accept — the units were
     * genuinely in transit (absent from both sides' counts) until now, so
     * this is the actual "physically received" moment. See docs/FIX_PLAN.md's
     * "ব্রাঞ্চ ট্রান্সফার accept/reject" design note.
     *
     * Deliberately takes a plain id, not an implicit-bound StockTransfer —
     * this model's own BelongsToTenant scope filters by shop_id, which is
     * always the SOURCE shop (see the migration's docblock); the
     * destination branch's owner (the only one ever allowed to call this)
     * would never match that scope, so implicit binding would 404 every
     * legitimate call. withoutGlobalScopes() below is the real lookup.
     */
    public function accept(int $transfer)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user?->role === 'owner', 403);

        DB::transaction(function () use ($transfer, $user) {
            $locked = StockTransfer::withoutGlobalScopes()->whereKey($transfer)->lockForUpdate()->first();
            abort_if(! $locked, 404);
            abort_unless($locked->to_shop_id === $user->shop_id, 403, 'শুধু গ্রহণকারী শাখাই এটা গ্রহণ করতে পারবে।');
            abort_unless($locked->status === 'pending', 422, 'এই ট্রান্সফারটি আর অপেক্ষমাণ নেই।');

            // withoutGlobalScopes() throughout — the acting user's own
            // tenancy is the DESTINATION shop, which happens to be where
            // these particular rows live too, but being explicit (rather
            // than relying on that coincidence) matches reject()'s own
            // need for it just below (the SOURCE product, a different shop
            // than whoever is acting here).
            if ($locked->to_product_variant_id) {
                $variant = ProductVariant::withoutGlobalScopes()->whereKey($locked->to_product_variant_id)->lockForUpdate()->first();
                $variant?->increment('stock', $locked->qty);
            }
            $product = Product::withoutGlobalScopes()->whereKey($locked->to_product_id)->lockForUpdate()->first();
            $product?->increment('stock', $locked->qty);

            $locked->update(['status' => 'completed']);

            Activity::log('stockTransfer.accept', "{$locked->product_name} — {$locked->qty} ইউনিট গ্রহণ করা হয়েছে।", $locked);
        });

        return back()->with('success', 'ট্রান্সফার গ্রহণ করা হয়েছে — স্টক যুক্ত হয়েছে।');
    }

    /** Returns the held units to the SOURCE — they were decremented at store() time and must land somewhere, never silently vanish. Plain id for the same reason as accept() above. */
    public function reject(int $transfer)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user?->role === 'owner', 403);

        DB::transaction(function () use ($transfer, $user) {
            $locked = StockTransfer::withoutGlobalScopes()->whereKey($transfer)->lockForUpdate()->first();
            abort_if(! $locked, 404);
            abort_unless($locked->to_shop_id === $user->shop_id, 403, 'শুধু গ্রহণকারী শাখাই এটা প্রত্যাখ্যান করতে পারবে।');
            abort_unless($locked->status === 'pending', 422, 'এই ট্রান্সফারটি আর অপেক্ষমাণ নেই।');

            if ($locked->from_product_variant_id) {
                $variant = ProductVariant::withoutGlobalScopes()->whereKey($locked->from_product_variant_id)->lockForUpdate()->first();
                $variant?->increment('stock', $locked->qty);
            }
            $product = Product::withoutGlobalScopes()->whereKey($locked->from_product_id)->lockForUpdate()->first();
            $product?->increment('stock', $locked->qty);

            $locked->update(['status' => 'rejected']);

            Activity::log('stockTransfer.reject', "{$locked->product_name} — {$locked->qty} ইউনিট প্রত্যাখ্যান করা হয়েছে, উৎস শাখায় স্টক ফেরত।", $locked);
        });

        return back()->with('success', 'ট্রান্সফার প্রত্যাখ্যান করা হয়েছে — স্টক উৎস শাখায় ফেরত গিয়েছে।');
    }
}
