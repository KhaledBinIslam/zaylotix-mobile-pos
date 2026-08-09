<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        return Inertia::render('App/StockTransfers/Index', [
            'siblings' => $siblings,
            'products' => Product::orderBy('name')->get(['id', 'name', 'emoji', 'barcode', 'stock', 'sold_by_weight']),
            'recentTransfers' => StockTransfer::withoutGlobalScopes()
                ->where('from_shop_id', $shopId)->orWhere('to_shop_id', $shopId)
                ->with(['fromShop:id,name', 'toShop:id,name', 'user:id,name'])
                ->latest('id')->limit(30)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(Auth::guard('web')->user()?->role === 'owner', 403);

        $data = $request->validate([
            'to_shop_id' => ['required', 'integer'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'qty' => ['required', 'numeric', 'min:0.001'],
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

            if (! $sourceProduct->sold_by_weight && floor($data['qty']) != $data['qty']) {
                abort(422, "{$sourceProduct->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
            }
            if ($sourceProduct->stock < $data['qty']) {
                abort(422, "পর্যাপ্ত স্টক নেই: {$sourceProduct->name} (আছে {$sourceProduct->stock})।");
            }

            $sourceProduct->decrement('stock', $data['qty']);

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
            $destProduct->increment('stock', $data['qty']);

            $transfer = StockTransfer::create([
                'shop_id' => $sourceShop->id,
                'from_shop_id' => $sourceShop->id,
                'to_shop_id' => $destShop->id,
                'from_product_id' => $sourceProduct->id,
                'to_product_id' => $destProduct->id,
                'product_name' => $sourceProduct->name,
                'qty' => $data['qty'],
                'user_id' => Auth::guard('web')->id(),
            ]);

            Activity::log('stockTransfer.create', "{$sourceProduct->name} — {$data['qty']} ইউনিট '{$sourceShop->name}' থেকে '{$destShop->name}'-এ পাঠানো হয়েছে।", $transfer, [
                'qty' => $data['qty'], 'to_shop' => $destShop->name,
            ]);
        });

        return back()->with('success', 'স্টক পাঠানো হয়েছে।');
    }
}
