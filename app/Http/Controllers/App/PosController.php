<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CheckoutRequest;
use App\Models\Customer;
use App\Models\GatewayPayment;
use App\Models\HeldCart;
use App\Models\PaymentGatewayCredential;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\BillingPreview;
use App\Support\PromotionEngine;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PosController extends Controller
{
    /**
     * Above this many products, index() stops sending the whole catalog
     * as a page prop and switches to server-side search + a background
     * sync into the offline IndexedDB cache instead (see
     * useOfflineCatalog.js and docs/FIX_PLAN.md's scale-follow-up design
     * note). `config/pos.php` (env `POS_FULL_CATALOG_LIMIT`, default
     * 1500) — no real shop is anywhere near this today; it exists so a
     * future 10,000+ SKU pharmacy/superstore doesn't ship a multi-
     * megabyte JSON payload on every POS load, never to change behavior
     * for a normal-sized shop. A plain config value, not a class
     * constant, so it's tunable without a deploy and a test can exercise
     * the 'search' path against a small, fast-to-seed product count.
     */
    private static function fullCatalogLimit(): int
    {
        return (int) config('pos.full_catalog_limit');
    }

    public function index(Request $request)
    {
        $shop = Tenancy::shop();

        // Same check HomeController::index() already had — needed here too
        // now that POS, not Home, is the default post-login landing page
        // (see DefaultLandingRoute) for anyone who can reach it. Without
        // this, a brand-new owner would land straight on POS and skip the
        // first-run wizard entirely just by never passing through Home.
        if (Auth::guard('web')->user()?->role === 'owner' && ! $shop?->onboarded_at) {
            return redirect()->route('app.onboarding');
        }

        // a restaurant-type shop's real "sell" screen is the Tables/order
        // flow (table + takeaway/delivery channel + KOT) — this plain
        // product-only checkout has none of that and is the wrong screen
        // for that business type. The sidebar/mobile-nav links already
        // route around this normally; this is a server-side backstop for
        // any other way here (a bookmark, a stale link, browser back) so a
        // restaurant shop can never actually land on it.
        //
        // Deliberately checks the shop's actual business type, NOT the
        // restaurant_tables feature flag — a flag can be granted to a
        // non-restaurant shop (e.g. the flagship demo, seeded with every
        // feature to showcase the platform) without making it a restaurant,
        // and that mismatch previously sent a grocery shop's cashier here
        // instead of the normal product checkout, breaking "add to cart"
        // entirely for that account. See Shop::isRestaurant().
        if ($shop->isRestaurant()) {
            return redirect()->route('app.restaurant.tables.index');
        }

        $relations = self::catalogRelations($shop);

        // Scale guard — see fullCatalogLimit()'s own docblock above.
        // Below it: byte-identical to this app's original behavior, just
        // with two new props added (catalogMode/productCount) that the
        // frontend doesn't act on yet for a 'full' shop. Above it: no
        // products are sent at all — the cashier searches instead (see
        // search() below) and useOfflineCatalog.js's background sync
        // keeps offline search/sale working the same way it always has.
        $productCount = Product::count();
        $catalogMode = $productCount > self::fullCatalogLimit() ? 'search' : 'full';

        $products = $catalogMode === 'full'
            ? Product::with($relations)->orderByDesc('id')->get()
            : [];

        $categories = ProductCategory::orderBy('name')->get();

        // an open quotation being converted to a real sale — the cart is
        // prefilled client-side from this, but the actual stock/money
        // movement still only ever happens through the normal checkout()
        // below, never here
        $quotation = null;
        if ($request->filled('quotation')) {
            // an expired-but-still-open quote is never prefilled — matches
            // the hard block in performCheckout() below (see its own
            // comment); checked here too so the cashier gets a clean "not
            // found" instead of a cart that then fails at checkout
            $quotation = Quotation::with('items')->where('status', 'open')
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->startOfDay()))
                ->find($request->get('quotation'));
        }

        // clothing shops get a category -> product -> color/size flow tuned
        // for that vertical (see Clothing/Pos.vue's docblock); every other
        // business type keeps the shared screen unchanged — same props,
        // same checkout/barcode endpoints either way, only the component differs
        $component = $shop->businessType?->slug === 'clothing' ? 'App/Clothing/Pos' : 'App/Pos/Index';

        return Inertia::render($component, [
            'products' => $products,
            'categories' => $categories,
            'catalogMode' => $catalogMode,
            'productCount' => $productCount,
            'salesMode' => $shop->sales_mode,
            // full cart_data isn't needed until actually resumed — this list
            // is just enough to show a picker (label + how long it's been held)
            'heldCarts' => HeldCart::orderByDesc('id')->get(['id', 'label', 'created_at'])
                ->map(fn ($h) => ['id' => $h->id, 'label' => $h->label, 'created_at' => $h->created_at->diffForHumans()]),
            'prefillQuotation' => $quotation,
            // just which providers are usable — never the credentials
            // themselves, so this is safe to expose to a cashier session
            // too (they're the ones actually running checkout)
            'activeGatewayProviders' => PaymentGatewayCredential::where('shop_id', $shop->id)->where('is_active', true)->pluck('provider'),
        ]);
    }

    /**
     * The exact relation set every POS product payload carries — shared by
     * index() (full-catalog mode), search(), and catalogSyncChunk(), so a
     * product found through search/background-sync always has the same
     * shape as one that arrived via the full catalog, and add-to-cart/IMEI
     * picker/pack-size pricing/batch display behave identically either way.
     */
    private static function catalogRelations(Shop $shop): array
    {
        $relations = ['category', 'unit', 'productUnits.unit', 'variants'];
        // in-stock IMEIs, only for a shop that actually tracks them — a
        // cashier picking from a list of real in-stock units beats typing a
        // 15-digit number from memory/the box, which is exactly what the
        // old plain free-text field forced. Omitted entirely (relation
        // simply absent from the payload) for every other business type.
        if ($shop->hasFeature('serial_tracking')) {
            $relations['serials'] = fn ($q) => $q->available()->select('id', 'product_id', 'imei', 'warranty_expiry');
        }
        // soonest-expiring batch still in stock — same relation Stock/Index
        // already uses (ProductController::index), just surfaced here too
        // so a pharmacy cashier can see/warn about it without leaving POS.
        // FEFO already picks the actual batch automatically at checkout
        // (BatchStock::deduct); this is purely visibility.
        if ($shop->hasFeature('batch_tracking')) {
            $relations[] = 'nearestBatch';
        }

        return $relations;
    }

    /**
     * Live typed-ahead search for a shop over the configured catalog limit — the
     * cashier's replacement for filtering an in-memory list that no
     * longer exists client-side once index() stops sending it. Same
     * search columns as ProductController::index()'s Stock-page search,
     * same relation shape as this controller's own index() always sent,
     * so everything downstream of "found a product" works identically to
     * the full-catalog path. Capped at 50 — a cashier scans a search
     * result, never paginates it.
     */
    public function search(Request $request)
    {
        $shop = Tenancy::shop();
        $q = trim((string) $request->get('q', ''));
        $categoryId = $request->get('category_id');

        $products = Product::with(self::catalogRelations($shop))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('name_en', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%")
                ->orWhere('generic_name', 'like', "%{$q}%")))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['products' => $products]);
    }

    /**
     * Background full-catalog sync for the offline cache (see
     * useOfflineCatalog.js) — only reachable for a shop actually over the
     * configured catalog limit; a normal shop never calls or needs this.
     * Cursor-paginated by id descending (same order index()/search() use)
     * rather than offset pagination, specifically so the repeated chunk
     * calls this makes can never skip or repeat a row if a product is
     * created/deleted mid-sync the way an offset page could.
     */
    public function catalogSyncChunk(Request $request)
    {
        $shop = Tenancy::shop();
        abort_unless(Product::count() > self::fullCatalogLimit(), 403);

        $afterId = $request->get('after_id');
        $chunkSize = (int) config('pos.catalog_sync_chunk_size');

        $products = Product::with(self::catalogRelations($shop))
            ->when($afterId, fn ($query) => $query->where('id', '<', $afterId))
            ->orderByDesc('id')
            ->limit($chunkSize)
            ->get();

        return response()->json([
            'products' => $products,
            // A full chunk might have more behind it; a short one (fewer
            // rows than asked for) means this was the last page — null
            // tells the frontend to stop, instead of one wasted empty
            // round trip to find that out.
            'next_after_id' => $products->count() === $chunkSize ? $products->last()->id : null,
        ]);
    }

    /**
     * Create a sale atomically: lock every line's product row (consistent
     * ascending id order to avoid deadlocks under concurrent checkouts),
     * verify stock, decrement it, snapshot prices/costs onto sale_items,
     * update the customer ledger, and bump the shop's cash/bank balance —
     * all inside one transaction so a mid-way failure never leaves stock
     * decremented without a recorded sale, or a sale without stock movement.
     *
     * Each line may optionally name a `product_unit_id` (a pack size like
     * "box" or "strip" — see product_units table) instead of the product's
     * base unit. The price and cost are always resolved server-side from
     * that pack's own price and its factor × base cost — never trusted from
     * the client — and stock is always decremented in base units (qty × factor)
     * so a sale of "1 box" and a sale of "100 pieces" leave stock identical.
     */
    public function checkout(CheckoutRequest $request)
    {
        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();
        $data = $request->validated();

        // Real bug: an offline sale queued under one shop (classic
        // trigger — an admin reusing one browser across impersonation
        // sessions for different shops) could sit in the browser's
        // IndexedDB past that session ending, then get replayed later
        // under a DIFFERENT shop's session. Every product_id in it
        // genuinely doesn't exist under the new tenant scope, so the old
        // code gave a misleading generic "product not found" for every
        // line — no signal that the real problem was a wrong shop, not a
        // missing product, and retrying forever repeated the identical
        // failure. A client built after this fix always sends its own
        // shop_id, so a mismatch is caught HERE, before the product
        // lookup even runs, with an unambiguous message naming the shop
        // it was really queued for. An older cached client/already-queued
        // entry with no shop_id simply skips this check — never worse
        // than before.
        if (! empty($data['shop_id']) && (int) $data['shop_id'] !== $shopId) {
            if (! empty($data['offline_replay'])) {
                Log::warning('Offline sync replay rejected: shop mismatch', [
                    'current_shop_id' => $shopId,
                    'queued_shop_id' => $data['shop_id'],
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);
            }

            return response()->json([
                'message' => 'এই বিলটি অন্য একটি দোকানের জন্য সারিবদ্ধ হয়েছিল'.(! empty($data['shop_name']) ? " ({$data['shop_name']})" : '').' — এই দোকানের সেশনে সিঙ্ক করা যাবে না। সঠিক দোকানে লগইন/ইমপারসোনেট করে আবার চেষ্টা করুন, অথবা এই বিলটি বাতিল করুন।',
                'shop_mismatch' => true,
                'queued_for_shop_name' => $data['shop_name'] ?? null,
            ], 409);
        }

        // Diagnostic-only (not business logic — never changes what's
        // returned): a sync replay that gets rejected (stock ran out,
        // validation, anything performCheckout() throws) used to leave NO
        // trace in the server log at all, since a deliberate abort()/422 is
        // never reported the way a real crash is. Reported live ("1 sale(s)
        // failed to sync" with nothing matching in storage/logs/laravel.log)
        // — this exists so the NEXT occurrence actually leaves something to
        // read instead of another silent dead end.
        if (! empty($data['offline_replay'])) {
            try {
                $sale = self::performCheckout($data, $shopId, $userId);
            } catch (\Throwable $e) {
                Log::warning('Offline sync replay rejected at checkout', [
                    'shop_id' => $shopId,
                    'client_uuid' => $data['client_uuid'] ?? null,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'response_body' => $e instanceof \Illuminate\Http\Exceptions\HttpResponseException
                        ? $e->getResponse()->getContent()
                        : null,
                ]);
                throw $e;
            }

            return response()->json(['sale' => $sale]);
        }

        $sale = self::performCheckout($data, $shopId, $userId);

        return response()->json(['sale' => $sale]);
    }

    /**
     * Reported bug: the billing popup computed its own displayed total
     * client-side (manual discount only — never a coupon's or redeemed
     * loyalty points' discount), so it tendered a higher amount than the
     * server's real, lower, post-coupon total and tripped checkout's own
     * "payment exceeds total" guard. The client now calls this whenever the
     * coupon code or redeemed-points value changes, and uses the returned
     * figures to display/tender from instead of recomputing them itself —
     * see BillingPreview's own docblock for why this can never drift from
     * what checkout() actually charges.
     */
    public function billingPreview(CheckoutRequest $request)
    {
        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();
        $breakdown = BillingPreview::compute($request->validated(), $shopId, $userId);

        return response()->json($breakdown);
    }

    /**
     * An offline sale the cashier deliberately gives up on resolving (see
     * the failed-queue resolution sheet, AppLayout.vue) — this never
     * reached performCheckout(), so there's no Sale row to void; this is
     * purely a best-effort audit trail of a bill that existed and was
     * knowingly abandoned, logged against whichever shop is CURRENTLY
     * live (the queuing shop, if different — see the shop-mismatch
     * check in checkout() — may no longer even be this session's tenant).
     */
    public function discardOffline(Request $request)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'payload' => ['nullable', 'array'],
        ]);

        Activity::log(
            'pos.offlineSaleDiscarded',
            'একটি সিঙ্ক-ব্যর্থ অফলাইন বিল বাতিল করা হয়েছে।'.(! empty($data['reason']) ? ' কারণ: '.$data['reason'] : ''),
            null,
            ['reason' => $data['reason'] ?? null, 'payload' => $data['payload'] ?? null],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Reported gap: a combo BOGO's free reward item never showed up unless
     * the cashier already knew to add it themselves. Cheap, read-only (no
     * locks, no transaction) — called on every cart change, unlike
     * billingPreview() above which only runs the full checkout-pricing
     * simulation when a coupon/redeem is actually active.
     */
    public function bogoSuggestions(Request $request)
    {
        $shop = Tenancy::shop();
        if (! $shop?->hasFeature('promotions')) {
            return response()->json(['suggestions' => []]);
        }

        $items = $request->validate([
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric'],
        ])['items'];

        return response()->json(['suggestions' => PromotionEngine::suggestMissingBogoItems($items)]);
    }

    /**
     * The one place a real Sale gets created from a priced cart — shared by
     * the normal authenticated checkout() above and GatewayWebhookController,
     * which calls this the instant a payment-gateway webhook confirms money
     * genuinely arrived (never on the strength of a browser redirect alone).
     * Everything here is byte-for-byte what checkout() always did; this is a
     * pure extraction, not a behavior change, specifically so the two
     * callers can never compute a sale differently from each other.
     *
     * @param  array<int,array{method:string,amount:float}>|null  $forcedPayments  when given, replaces $data['payments'] entirely — used by the gateway webhook path, where the "payment" is the gateway capture itself, never a client-submitted tender.
     * @param  bool  $autoCoverTotal  used only by GatewayCheckoutController's price preview (see App\Support\Gateways\GatewayPricePreview) — skips the tendered/due-remainder checks entirely and synthesizes a single payment covering the total exactly, since the whole point of that call is to run this same pricing/stock-validation logic and then roll it back just to learn what the total actually is, before a real payment exists to attach.
     */
    public static function performCheckout(array $data, int $shopId, ?int $userId, ?array $forcedPayments = null, bool $autoCoverTotal = false): Sale
    {
        return DB::transaction(function () use ($data, $shopId, $userId, $forcedPayments, $autoCoverTotal) {
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            // Idempotency: a client-generated UUID identifies "this one
            // checkout attempt" across retries. Without it, the offline
            // queue's replay-on-reconnect (or a lost response on a normal
            // online checkout whose request still reached the server) would
            // resubmit an already-committed sale — double-selling and
            // double-decrementing stock. The shop row is already locked
            // above, so this check and the eventual Sale::create() below can
            // never race each other for the same shop; a replay with the
            // same uuid just gets back the sale that already exists.
            if (! empty($data['client_uuid'])) {
                $existing = Sale::where('client_uuid', $data['client_uuid'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            // this checkout is converting an open quotation — locked and
            // re-checked here (not just once at page-load) so a double-tap
            // or a second tab can't convert the same quote into two sales
            $quotation = null;
            $quotedNetUnitByProduct = [];
            if (! empty($data['quotation_id'])) {
                if (! $shop->hasFeature('quotations')) {
                    abort(422, 'কোটেশন ফিচারটি চালু নেই।');
                }
                $quotation = Quotation::whereKey($data['quotation_id'])->lockForUpdate()->with('items')->first();
                if (! $quotation || $quotation->status !== 'open') {
                    abort(422, 'এই কোটেশনটি ইতিমধ্যে বিক্রয়ে রূপান্তরিত বা বাতিল হয়ে গেছে।');
                }
                // Confirmed bug (see docs/FIX_PLAN.md Phase 6): valid_until
                // was stored and displayed but never actually checked
                // anywhere — an expired-but-still-open quote could be
                // converted with no warning. Date-only comparison (not
                // isPast(), which would treat "valid until today" as
                // already expired from midnight) — a quote is good through
                // the end of its valid_until date.
                if ($quotation->valid_until && $quotation->valid_until->lt(now()->startOfDay())) {
                    abort(422, 'এই কোটেশনের মেয়াদ শেষ হয়ে গেছে।');
                }
                // Confirmed gap: the quoted price was only ever honored by a
                // client-side compensating discount the cashier could edit
                // or bypass — nothing server-side actually capped what the
                // customer pays at the price they were quoted. Net per-unit
                // price (the quote's own line discount folded in), so a
                // quote that already included a discount is still fully
                // honored, not just its headline price.
                foreach ($quotation->items as $qi) {
                    if (! $qi->product_id || (float) $qi->qty <= 0) {
                        continue;
                    }
                    $netUnit = ((float) $qi->price * (float) $qi->qty - (float) $qi->discount) / (float) $qi->qty;
                    $quotedNetUnitByProduct[$qi->product_id] = isset($quotedNetUnitByProduct[$qi->product_id])
                        ? min($quotedNetUnitByProduct[$qi->product_id], $netUnit)
                        : $netUnit;
                }
            }

            $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values();

            // Set only by the offline-sync replay path (useOfflineSync.js),
            // never a live checkout — a genuinely-queued sale rung up
            // before its product was later deleted should still be able
            // to sync (the sale really happened); a fresh/live attempt to
            // sell something the shop has since discontinued should not.
            $allowTrashedProducts = ! empty($data['offline_replay']);
            $productsQuery = fn () => $allowTrashedProducts ? Product::withTrashed() : Product::query();

            // whole-cart toggle, not per-line — a product with no
            // wholesale_price of its own just always sells at its normal
            // price regardless of this flag. Computed here (not just once
            // at the pricing loop below) so priceTiers only gets eager-
            // loaded for a shop that can actually use them.
            $isWholesale = $shop->hasFeature('wholesale_pricing') && ($data['sale_type'] ?? 'retail') === 'wholesale';
            $tiersOn = $isWholesale && $shop->hasFeature('wholesale_tiers');

            $products = $productsQuery()->whereIn('id', $productIds)
                ->withCount('variants')
                ->when($tiersOn, fn ($query) => $query->with('priceTiers'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Offline-sync recovery: a product_id that's missing outright
            // (soft-deleted and not covered above, or — the actual root
            // cause behind the reported bug — simply never existed under
            // THIS shop's id space because the entry was queued under a
            // different one) can sometimes still be matched by its
            // barcode snapshot. Scoped to this same tenant via the normal
            // query above, so this can never pull in a different shop's
            // product under a mismatched id; it only helps when the
            // genuinely-right product for this shop happens to carry a
            // different id than the client remembered (e.g. the original
            // row really was deleted and recreated).
            $missingIds = $productIds->diff($products->keys());
            if ($missingIds->isNotEmpty()) {
                $barcodeByMissingId = collect($data['items'])
                    ->filter(fn ($item) => $missingIds->contains($item['product_id']) && ! empty($item['product_barcode']))
                    ->pluck('product_barcode', 'product_id');

                if ($barcodeByMissingId->isNotEmpty()) {
                    $byBarcode = $productsQuery()->whereIn('barcode', $barcodeByMissingId->unique()->values())
                        ->withCount('variants')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('barcode');

                    // Rewrite the item's own product_id to the resolved
                    // product's REAL id (not left as the original missing
                    // one) — every later lookup in this method ($products,
                    // $stockRemaining, $sellableRemaining, ...) is keyed by
                    // a product's actual ->id, so the resolved product must
                    // be stored under that same key, not the stale id the
                    // client remembered.
                    foreach ($data['items'] as $index => &$item) {
                        $barcode = $barcodeByMissingId->get($item['product_id']);
                        if ($barcode !== null && $byBarcode->has($barcode)) {
                            $resolved = $byBarcode->get($barcode);
                            $products->put($resolved->id, $resolved);
                            $item['product_id'] = $resolved->id;
                        }
                    }
                    unset($item);
                    $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values();
                }
            }

            // Collected upfront, all at once — rather than aborting on the
            // FIRST missing product the way every other per-line check
            // below still does — specifically so a cart with more than one
            // problem item can be reported (and resolved) in one pass
            // instead of a frustrating one-at-a-time retry loop. Every
            // OTHER validation (stock, unit/variant mismatch, qty) is
            // unchanged and still fails fast on the first offender, same
            // as before this fix.
            $stillMissing = $productIds->diff($products->keys());
            if ($stillMissing->isNotEmpty()) {
                $nameByProductId = collect($data['items'])->pluck('product_name', 'product_id');
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => 'কিছু পণ্য পাওয়া যায়নি। নিচের প্রতিটি সমস্যা আলাদাভাবে সমাধান করুন।',
                    'problem_items' => $stillMissing->map(fn ($id) => [
                        'product_id' => $id,
                        'product_name' => $nameByProductId->get($id) ?: null,
                    ])->values(),
                ], 422));
            }

            $unitIds = collect($data['items'])->pluck('product_unit_id')->filter()->unique();
            $productUnits = $unitIds->isEmpty()
                ? collect()
                : ProductUnit::whereIn('id', $unitIds)->with('unit')->get()->keyBy('id');

            // Variants (size/color) have their own independent stock, unlike
            // product_units — lock them too so a variant's stock can't be
            // oversold by a concurrent checkout any more than the product
            // itself can.
            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique();
            $productVariants = $variantIds->isEmpty()
                ? collect()
                : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $profit = 0;
            $lines = [];

            // Tracks stock left to allocate per product as lines are
            // validated, since a single cart can carry more than one line
            // for the same product (e.g. "1 box" + "3 loose pieces" of the
            // same item) — checking each line against $product->stock alone
            // would let every line pass individually while their combined
            // base-unit total oversells the actual stock.
            $stockRemaining = $products->map(fn ($p) => $p->stock);
            // same idea, one level down — a cart can carry two lines of the
            // same variant too (rare, but the same oversell trap applies)
            $variantStockRemaining = $productVariants->map(fn ($v) => $v->stock);

            // computed once, up front — needed here (not just later at
            // deduction time) so an expired-batch shortfall rejects the
            // whole sale before anything is written, the same way a plain
            // stock shortfall does
            $batchTrackingOn = $shop->hasFeature('batch_tracking');
            $sellableRemaining = $batchTrackingOn
                ? $products->map(fn ($p) => \App\Support\BatchStock::sellableQty($p))
                : collect();

            // BOGO/combo promotions (buy N of A, get M of B free/discounted)
            // auto-apply here — computed once against the raw item list
            // before the per-line loop below folds it into each matching
            // line's own discount, same as a manually-typed one would be.
            // Gated the same way batch/serial tracking are — a shop whose
            // 'promotions' feature was later revoked stops having its
            // leftover promotion rows take effect, without needing to
            // delete them.
            $bogoResult = $shop->hasFeature('promotions')
                ? PromotionEngine::computeAutoLineDiscounts($data['items'], $shopId)
                : ['discounts' => [], 'triggeredIds' => []];
            $autoDiscounts = $bogoResult['discounts'];

            foreach ($data['items'] as $index => $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    abort(422, 'পণ্যটি খুঁজে পাওয়া যায়নি — হয়তো এই মুহূর্তে মুছে ফেলা হয়েছে। তালিকা রিলোড করে আবার চেষ্টা করুন।');
                }

                // MOQ is a hard gate, not a soft fallback (see docs/
                // FIX_PLAN.md's Phase 30 design note, Part A) — a wholesale
                // line below its own product's minimum is rejected outright,
                // never silently sold at retail. A product with no MOQ set
                // (the overwhelming majority, including every shop that
                // never turned wholesale_tiers on) has none enforced, same
                // as today.
                if ($isWholesale && $product->wholesale_moq !== null && (float) $item['qty'] < $product->wholesale_moq) {
                    abort(422, "{$product->name}-এর পাইকারি সর্বনিম্ন পরিমাণ {$product->wholesale_moq}, আপনি দিয়েছেন {$item['qty']}।");
                }

                $factor = 1;
                // Real bug, found while building dead-stock clearance (see
                // docs/FIX_PLAN.md): discount_price has existed for a while
                // (settable in Stock/Index.vue, shown on barcode labels and
                // exports) but was never actually read here — a cashier
                // always charged the full `price` regardless, silently
                // ignoring whatever markdown the owner had set. Confirmed
                // with Khaled this was never intentional. Only applies to a
                // retail base-product line (never wholesale, which already
                // has its own, usually-lower price; never a pack-unit or
                // variant line, each priced independently below) — and only
                // when actually set, so a shop that's never touched this
                // field sees zero behavior change.
                // Tier takes priority over the flat wholesale_price when
                // both exist — a product with no tier defined (or whose
                // qty doesn't clear any tier's min_qty) falls straight
                // through to the exact same flat-price logic as before, so
                // a shop using only the simple flat price sees zero change.
                $tierPrice = ($tiersOn && $isWholesale) ? $product->wholesaleTierPriceForQty((float) $item['qty']) : null;
                $unitPrice = match (true) {
                    $tierPrice !== null => $tierPrice,
                    $isWholesale && $product->wholesale_price !== null => (float) $product->wholesale_price,
                    default => (float) ($product->discount_price ?? $product->price),
                };
                if (isset($quotedNetUnitByProduct[$product->id])) {
                    $unitPrice = min($unitPrice, $quotedNetUnitByProduct[$product->id]);
                }
                $unitCost = $product->cost;
                $unitLabel = null;
                $variant = null;
                $variantLabel = null;

                if (! empty($item['product_unit_id']) && ! empty($item['product_variant_id'])) {
                    abort(422, "একই লাইনে প্যাক সাইজ ও ভ্যারিয়েন্ট দুটোই বাছাই করা যাবে না।");
                }

                // Fractional qty only makes sense on a weighed product's own
                // base-unit line — a pack size (factor is a whole multiple)
                // or a variant (always whole units) never allows it, and
                // neither does a plain (non-weighed) product line.
                $isWeighedBaseLine = $product->sold_by_weight && empty($item['product_unit_id']) && empty($item['product_variant_id']);
                if (! $isWeighedBaseLine && floor($item['qty']) != $item['qty']) {
                    abort(422, "{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
                }

                // A product with variants must never be sold "in general" —
                // products.stock only means anything as the sum of its
                // variants, so a sale that doesn't name which variant it
                // came from would decrement the total without ever
                // decrementing a specific variant, breaking that invariant.
                if ($product->variants_count > 0 && empty($item['product_variant_id'])) {
                    abort(422, "{$product->name}-এর জন্য একটা সাইজ/রং বাছাই করুন।");
                }

                if (! empty($item['product_unit_id'])) {
                    $pu = $productUnits->get($item['product_unit_id']);
                    if (! $pu || $pu->product_id !== $product->id) {
                        abort(422, "এই এককটি {$product->name}-এর জন্য সঠিক নয়। পেজ রিলোড করে আবার চেষ্টা করুন।");
                    }
                    $factor = $pu->factor;
                    $unitPrice = $pu->price;
                    $unitCost = $product->cost * $factor;
                    $unitLabel = $pu->unit->name;
                } elseif (! empty($item['product_variant_id'])) {
                    $variant = $productVariants->get($item['product_variant_id']);
                    if (! $variant || $variant->product_id !== $product->id) {
                        abort(422, "এই ভ্যারিয়েন্টটি {$product->name}-এর জন্য সঠিক নয়। পেজ রিলোড করে আবার চেষ্টা করুন।");
                    }
                    $unitPrice = $variant->effectivePrice();
                    $unitCost = $variant->effectiveCost();
                    $variantLabel = $variant->label();
                }

                $baseQtyNeeded = $item['qty'] * $factor;

                if ($variant) {
                    if ($variantStockRemaining[$variant->id] < $item['qty']) {
                        abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} ({$variantLabel}) (আছে {$variantStockRemaining[$variant->id]}, লাগবে {$item['qty']})।");
                    }
                    $variantStockRemaining[$variant->id] -= $item['qty'];
                } elseif ($stockRemaining[$product->id] < $baseQtyNeeded) {
                    abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} (আছে {$stockRemaining[$product->id]}, লাগবে {$baseQtyNeeded})।");
                } elseif ($batchTrackingOn && $sellableRemaining[$product->id] < $baseQtyNeeded) {
                    // real stock exists, but not enough of it outside an
                    // expired batch — reject outright rather than letting
                    // deduct() silently under-cover the allocation the way
                    // an ordinary untracked-stock shortfall is allowed to
                    abort(422, "{$product->name}-এর পর্যাপ্ত অ-মেয়াদোত্তীর্ণ স্টক নেই — কিছু স্টক এক্সপায়ার্ড ব্যাচে আটকে আছে (বিক্রিযোগ্য আছে {$sellableRemaining[$product->id]}, লাগবে {$baseQtyNeeded})।");
                } else {
                    $stockRemaining[$product->id] -= $baseQtyNeeded;
                    if ($batchTrackingOn) {
                        $sellableRemaining[$product->id] -= $baseQtyNeeded;
                    }
                }

                $rawLineTotal = $unitPrice * $item['qty'];
                // per-line discount is a flat taka amount, never more than
                // the line's own total — never trust the client's arithmetic.
                // Auto-applied BOGO/combo value (if this line is a matching
                // reward line) stacks on top of whatever the cashier typed.
                $lineDiscount = min((float) ($item['discount'] ?? 0) + ($autoDiscounts[$index] ?? 0), $rawLineTotal);
                $lineTotal = $rawLineTotal - $lineDiscount;
                $lineProfit = ($unitPrice - $unitCost) * $item['qty'] - $lineDiscount;

                $subtotal += $lineTotal;
                $profit += $lineProfit;

                $lines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'qty' => $item['qty'],
                    'price' => $unitPrice,
                    'discount' => $lineDiscount,
                    'cost' => $unitCost,
                    'unit_label' => $unitLabel,
                    'variant_label' => $variantLabel,
                    'unit_factor' => $factor,
                    'base_qty' => $baseQtyNeeded,
                    'imei' => empty($item['imei']) ? null : trim($item['imei']),
                ];
            }

            // resolve / create customer — moved ahead of discount computation
            // because loyalty-point redemption (below) needs to know who's
            // paying before the final discount/total can be settled. An
            // existing customer is locked so a redemption's "does the
            // balance actually cover this" check can't race a concurrent
            // checkout the same way the purchases/supplier-return fixes
            // this session had to guard against.
            $customer = null;
            $phone = trim($data['customer_phone'] ?? '');
            $name = trim($data['customer_name'] ?? '');

            if ($phone !== '') {
                $customer = Customer::where('phone', $phone)->lockForUpdate()->first();
                if (! $customer) {
                    $customer = Customer::create([
                        'shop_id' => $shopId,
                        'name' => $name ?: 'Customer',
                        'phone' => $phone,
                        'due' => 0,
                    ]);
                } elseif ($name !== '' && $customer->name !== $name) {
                    $customer->update(['name' => $name]);
                }
            }

            // Referral reward (see docs/FIX_PLAN.md's "রেফারেল" design
            // note) fires on this customer's FIRST real sale, never a
            // later one — captured here, before this checkout's own Sale
            // row exists, so "did they have any prior sale" can't
            // possibly see this transaction's own row.
            $isCustomersFirstSale = $customer && ! Sale::where('customer_id', $customer->id)->exists();

            // loyalty point redemption — a flat taka discount, same as a
            // coupon, but paid for out of the customer's own point balance
            // instead of the shop's margin
            $redeemPoints = (int) ($data['redeem_points'] ?? 0);
            $loyaltyDiscount = 0.0;
            if ($redeemPoints > 0) {
                if (! $shop->hasFeature('loyalty_points')) {
                    abort(422, 'লয়্যালটি পয়েন্ট ফিচারটি চালু নেই।');
                }
                if (! $customer) {
                    abort(422, 'পয়েন্ট রিডিম করতে কাস্টমারের ফোন নম্বর দিন।');
                }
                if ($redeemPoints > $customer->loyalty_points) {
                    abort(422, "এত পয়েন্ট নেই। বর্তমান পয়েন্ট: {$customer->loyalty_points}।");
                }
                $loyaltyDiscount = round($redeemPoints * (float) $shop->loyalty_point_value, 2);
            }

            // coupon discount stacks on top of the manual overall discount
            // and any point redemption, all clamped together so nothing can
            // push the bill negative
            $couponResult = $shop->hasFeature('promotions')
                ? PromotionEngine::resolveCoupon($data['coupon_code'] ?? null, $subtotal)
                : ['discount' => 0.0, 'promotion' => null];
            // free/staff-meal sale — the whole bill is comped, tracked
            // separately from a manual discount (see Reports::complimentaryReport()).
            // Forcing discount = subtotal here means $total lands on 0 and
            // flows through every check below (tender validation, due-needs-
            // customer, payment_mode) exactly like any other fully-discounted
            // sale already does — no special-casing needed past this point.
            $isComplimentary = (bool) ($data['complimentary'] ?? false);
            $manualDiscount = (float) ($data['discount'] ?? 0);
            $discount = $isComplimentary ? $subtotal : min($manualDiscount + $couponResult['discount'] + $loyaltyDiscount, $subtotal);
            $total = $subtotal - $discount;
            $profit -= $discount;

            $vat = 0;
            if ($shop->vat_mode === 'full') {
                // shop-configured rate, backed out of the already VAT-inclusive
                // price — e.g. rate=15 gives total*15/115, same shape for any rate
                $rate = (float) $shop->vat_rate;
                $vat = round($total * $rate / (100 + $rate), 2);
            } elseif ($shop->vat_mode === 'turnover') {
                $vat = round($total * (float) $shop->turnover_rate / 100, 2);
            }

            // unlike VAT above (which is backed out of $total for display —
            // Bangladeshi retail prices are already VAT-inclusive), a
            // restaurant service charge is genuinely additive: it's a
            // percentage added ON TOP of the bill, so it must be folded into
            // $total before payments/due are computed below
            $serviceCharge = 0;
            if ($shop->service_charge_rate !== null) {
                $serviceCharge = round($total * (float) $shop->service_charge_rate / 100, 2);
                $total += $serviceCharge;
            }

            // points earned on what was actually paid (post every
            // discount, including any just-redeemed points) — never on the
            // pre-discount subtotal, or redeeming-then-immediately-earning
            // on the same taka would let points regenerate themselves
            $pointsEarned = 0;
            if ($customer && $shop->hasFeature('loyalty_points') && (float) $shop->loyalty_earn_rate > 0) {
                $pointsEarned = (int) floor($total / 100 * (float) $shop->loyalty_earn_rate);
            }

            if ($autoCoverTotal) {
                // price-preview mode — see the autoCoverTotal docblock above;
                // no real tender exists yet, so this synthesizes one that
                // exactly covers $total and skips every guard that only
                // makes sense once real money/tenders are involved
                $payments = collect([['method' => 'gateway', 'amount' => round($total, 2)]]);
                $tendered = round($total, 2);
                $dueRemainder = 0.0;
                $paymentMode = 'gateway';
            } else {
                // Multi-tender: sum whatever cash/bkash/nagad was actually
                // received; whatever's left of $total becomes the attached
                // customer's due. Never trust a client-computed remainder —
                // it's always $total minus the server-validated tender sum.
                $payments = collect($forcedPayments ?? $data['payments'] ?? [])
                    ->map(fn ($p) => ['method' => $p['method'], 'amount' => round((float) $p['amount'], 2)])
                    ->values();
                $tendered = round((float) $payments->sum('amount'), 2);

                if ($tendered - $total > 0.01) {
                    abort(422, 'পেমেন্টের পরিমাণ মোট বিলের চেয়ে বেশি হতে পারবে না।');
                }

                $dueRemainder = max(0, round($total - $tendered, 2));

                if ($dueRemainder > 0 && ! $customer) {
                    abort(422, 'বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন।');
                }

                // "ক্রেডিট লিমিট" — see docs/FIX_PLAN.md's design note. A
                // null limit (the default, on every shop until the owner
                // sets one) means unlimited, identical to today's behavior
                // — this only ever blocks once a real ceiling is on file.
                if ($dueRemainder > 0 && $customer && $shop->hasFeature('credit_limit')) {
                    $limit = $customer->effectiveCreditLimit();
                    if ($limit !== null && round((float) $customer->due + $dueRemainder, 2) > round($limit, 2) + 0.01) {
                        abort(422, "ক্রেডিট লিমিট ৳".round($limit)." অতিক্রম করবে (বর্তমান বাকি ৳{$customer->due})।");
                    }
                }

                // payment_mode stays a quick-glance single value for backward-
                // compatible display/filtering: the specific method when there's
                // exactly one, 'credit' when it's entirely on due, 'split'
                // whenever more than one tender method is involved or a tender
                // is combined with a due remainder.
                $distinctMethods = $payments->pluck('method')->unique()->count();
                $paymentMode = match (true) {
                    $total <= 0.01 => 'cash', // fully-discounted/free sale — nothing tendered or owed
                    $tendered <= 0.01 => 'credit',
                    $dueRemainder > 0.01 || $distinctMethods > 1 => 'split',
                    default => $payments->first()['method'],
                };
            }

            // invoice number — locked shop row above guarantees no collision.
            // A wholesale sale gets its own 'WS-' series under wholesale_tiers
            // specifically (see docs/FIX_PLAN.md's Phase 30 design note, Part
            // C) — a wholesale sale on a shop that only has the base
            // wholesale_pricing feature (not the fuller wholesale_tiers)
            // keeps the exact INV- series unchanged, same as retail always has.
            if ($isWholesale && $tiersOn) {
                $shop->wholesale_invoice_counter += 1;
                $invoiceNo = 'WS-'.$shop->wholesale_invoice_counter;
            } else {
                $shop->invoice_counter += 1;
                $invoiceNo = 'INV-'.$shop->invoice_counter;
            }

            $sale = Sale::create([
                'shop_id' => $shopId,
                'customer_id' => $customer?->id,
                'user_id' => $userId,
                'invoice_no' => $invoiceNo,
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'is_complimentary' => $isComplimentary,
                'service_charge' => $serviceCharge,
                'vat' => $vat,
                'total' => $total,
                'profit' => $profit,
                'payment_mode' => $paymentMode,
                'sale_type' => $isWholesale ? 'wholesale' : 'retail',
                'prescription_note' => $data['prescription_note'] ?? null,
                'coupon_code' => $couponResult['promotion']?->code,
                'points_earned' => $pointsEarned ?: null,
                'points_redeemed' => $redeemPoints ?: null,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            if ($quotation) {
                $quotation->update(['status' => 'converted', 'sale_id' => $sale->id]);
            }

            if ($couponResult['promotion']) {
                Promotion::whereKey($couponResult['promotion']->id)->increment('used_count');
            }
            if ($bogoResult['triggeredIds']) {
                Promotion::whereIn('id', $bogoResult['triggeredIds'])->increment('used_count');
            }

            // $batchTrackingOn was already computed above (needed earlier,
            // for the sellableQty pre-check) — not repeated here
            $serialTrackingOn = $shop->hasFeature('serial_tracking');

            foreach ($lines as $line) {
                $batchAllocations = null;
                $serial = null;

                if ($line['variant']) {
                    // products.stock is maintained as the live sum of its
                    // variants' stock — both move by the same amount so
                    // that invariant holds for every other report/alert
                    // that reads products.stock without knowing variants exist.
                    $line['variant']->applyStockDelta(-$line['qty']);
                    $line['product']->applyStockDelta(-$line['qty']);
                } else {
                    $line['product']->applyStockDelta(-$line['base_qty']);

                    // best-effort ingredient consumption for a recipe-linked
                    // product (e.g. a restaurant dish) — see IngredientConsumption
                    \App\Support\IngredientConsumption::apply($line['product'], $line['base_qty']);

                    // FEFO (soonest-expiry-first) — best-effort bookkeeping
                    // only, never blocks or alters the sale itself; see
                    // BatchStock for why it can't fully cover every sale.
                    // The returned allocation is snapshotted onto the
                    // sale_item so a later void can restore stock to the
                    // exact batch it came from. (Variant lines skip this —
                    // batches aren't variant-aware. Weighed lines skip it
                    // too — batches are a whole-unit pharmacy concept, a
                    // fractional-kg line has no batch of its own to deduct from.)
                    $batchAllocations = ($batchTrackingOn && ! $line['product']->sold_by_weight)
                        ? \App\Support\BatchStock::deduct($line['product'], (int) $line['base_qty'])
                        : null;

                    // Same best-effort spirit as batches — an IMEI typed at
                    // checkout marks that exact unit sold (for warranty
                    // lookup later); a line with no IMEI, or one that
                    // doesn't match any in-stock unit, still sells normally.
                    // (Also meaningless for a weighed line — no single unit
                    // to name a serial for.)
                    if ($serialTrackingOn && $line['imei'] && ! $line['product']->sold_by_weight) {
                        $serial = \App\Support\SerialStock::sell($line['product'], $line['imei']);
                    }
                }

                SaleItem::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'product_serial_id' => $serial?->id,
                    'product_name' => $line['product']->name,
                    'unit_label' => $line['unit_label'],
                    'variant_label' => $line['variant_label'],
                    'unit_factor' => $line['unit_factor'],
                    'qty' => $line['qty'],
                    'price' => $line['price'],
                    'discount' => $line['discount'],
                    'cost' => $line['cost'],
                    'batch_allocations' => $batchAllocations,
                ]);
            }

            foreach ($payments as $payment) {
                SalePayment::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                ]);

                if ($payment['method'] === 'cash') {
                    $shop->cash_balance += $payment['amount'];
                } else { // bkash / nagad
                    $shop->bank_balance += $payment['amount'];
                }
            }

            if ($dueRemainder > 0) {
                $customer->increment('due', $dueRemainder);
            }
            $shop->save();

            if ($customer) {
                $customer->increment('total_spent', $total);
                $customer->increment('visits');
                if ($redeemPoints > 0) {
                    $customer->decrement('loyalty_points', $redeemPoints);
                }
                if ($pointsEarned > 0) {
                    $customer->increment('loyalty_points', $pointsEarned);
                }

                // Abuse-prevention: $isCustomersFirstSale was captured
                // before this Sale existed (never true again for this
                // customer after this point), and referral_rewarded_at
                // ensures this specific referral is only ever paid out
                // once — a self-referral is already structurally
                // impossible (CustomerController::store() only lets
                // referred_by_customer_id be set at creation, before this
                // row has an id of its own to match against).
                if ($isCustomersFirstSale && $customer->referred_by_customer_id && ! $customer->referral_rewarded_at
                    && $shop->hasFeature('customer_referrals') && $shop->hasFeature('loyalty_points') && (int) $shop->referral_bonus_points > 0) {
                    $referrer = Customer::whereKey($customer->referred_by_customer_id)->lockForUpdate()->first();
                    if ($referrer) {
                        $referrer->increment('loyalty_points', (int) $shop->referral_bonus_points);
                        Activity::log('customer.referralReward', "{$referrer->name} {$customer->name}-কে রেফার করায় {$shop->referral_bonus_points} পয়েন্ট পুরস্কার পেয়েছেন।", $referrer, [
                            'referred_customer_id' => $customer->id,
                            'points' => $shop->referral_bonus_points,
                        ]);
                    }
                    $customer->update(['referral_rewarded_at' => now()]);
                }
            }

            // 'user' (cashier) added so the just-billed receipt sheet can
            // show it when memo_settings.show_cashier is on — every other
            // field that sheet needs (items/customer/payments) was already
            // loaded here
            return $sale->load('items', 'customer', 'payments', 'user');
        });
    }

    public function barcode(string $barcode)
    {
        // a variant's own barcode (e.g. printed for "নীল শার্ট M" specifically)
        // takes priority — it's more specific than the product's own barcode
        $variant = ProductVariant::with('product.productUnits.unit')->where('barcode', $barcode)->first();
        if ($variant) {
            $product = $variant->product;
            $product->setRelation('variants', $product->variants()->get());

            return response()->json(['found' => true, 'product' => $product, 'variant_id' => $variant->id]);
        }

        $product = Product::with(['productUnits.unit', 'variants'])->where('barcode', $barcode)->first();

        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true, 'product' => $product]);
    }
}
