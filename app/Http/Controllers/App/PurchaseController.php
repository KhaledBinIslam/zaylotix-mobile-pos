<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\StockIn;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Two ways a purchase can be recorded: instantly ('received', the only mode
 * that existed before this — cash/bank and stock move the moment it's
 * saved, for a shop that pays on the spot and walks out with the goods),
 * or as a placed order ('pending' — nothing moves until markReceived() is
 * called once the goods actually arrive, for a shop that orders ahead and
 * pays/receives later). The same money+stock logic is shared by both paths,
 * just deferred for the pending one.
 */
class PurchaseController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Purchases/Index', [
            'purchases' => Purchase::with(['product:id,name,emoji', 'supplierModel:id,name'])
                ->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $imeis = \App\Support\SerialStock::parseList($data['imeis'] ?? null);
        if ($imeis && ! empty($data['qty']) && count($imeis) !== (int) $data['qty']) {
            $given = count($imeis);
            return back()->withErrors(['imeis' => "IMEI সংখ্যা ({$given}) স্টকের পরিমাণের ({$data['qty']}) সাথে মিলছে না।"]);
        }

        $isPending = ($data['status'] ?? 'received') === 'pending';

        DB::transaction(function () use ($data, $imeis, $isPending) {
            $purchase = Purchase::create([
                'supplier' => $data['supplier'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'memo' => $data['memo'] ?? null,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'status' => $isPending ? 'pending' : 'received',
                // stashed for markReceived() to apply later — a received
                // purchase never needs this, its effects already landed below
                'pending_details' => $isPending ? [
                    'product_id' => $data['product_id'] ?? null,
                    'ingredient_id' => $data['ingredient_id'] ?? null,
                    'qty' => $data['qty'] ?? null,
                    'cost' => $data['cost'] ?? null,
                    'batch_no' => $data['batch_no'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'imeis' => $imeis,
                    'warranty_expiry' => $data['warranty_expiry'] ?? null,
                ] : null,
                'product_id' => $data['product_id'] ?? null,
                'ingredient_id' => $data['ingredient_id'] ?? null,
                'qty' => $data['qty'] ?? null,
                'date' => now()->toDateString(),
            ]);

            if ($isPending) {
                Activity::log('purchase.order', 'অর্ডার করা হয়েছে — '.number_format((float) $data['amount'], 2).' টাকা (এখনো গ্রহণ করা হয়নি)।', $purchase);

                return;
            }

            $this->applyEffects($data, $imeis);

            Activity::log('purchase.record', 'ক্রয় রেকর্ড করা হয়েছে — '.number_format((float) $data['amount'], 2).' টাকা।', $purchase, [
                'amount' => $data['amount'], 'method' => $data['method'],
            ]);
        });

        return back()->with('success', $isPending ? 'Purchase order recorded — mark it received once the goods arrive.' : 'Purchase recorded.');
    }

    /** The goods (and money, for cash/bank) actually arrive now — applies the same effects store() would have applied immediately for a 'received' purchase. */
    public function markReceived(Purchase $purchase)
    {
        DB::transaction(function () use ($purchase) {
            // locked and re-checked here, not just by the route firing once —
            // a double-tapped "mark received" button (or a client retry after
            // a timed-out-but-actually-succeeded request, plausible on the
            // patchy mobile networks this app's users are on) must never
            // apply the same purchase's stock/money effects twice
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                abort(422, 'এই ক্রয়টি ইতিমধ্যে গ্রহণ করা হয়েছে।');
            }

            $details = $locked->pending_details ?? [];

            $this->applyEffects([
                'method' => $locked->method,
                'amount' => (float) $locked->amount,
                'supplier_id' => $locked->supplier_id,
                'product_id' => $details['product_id'] ?? null,
                'ingredient_id' => $details['ingredient_id'] ?? null,
                'qty' => $details['qty'] ?? null,
                'cost' => $details['cost'] ?? null,
                'batch_no' => $details['batch_no'] ?? null,
                'expiry_date' => $details['expiry_date'] ?? null,
                'warranty_expiry' => $details['warranty_expiry'] ?? null,
            ], $details['imeis'] ?? []);

            $locked->update(['status' => 'received', 'pending_details' => null]);

            Activity::log('purchase.receive', 'অর্ডার গ্রহণ করা হয়েছে — '.number_format((float) $locked->amount, 2).' টাকা।', $locked);
        });

        return back()->with('success', 'Purchase marked received — stock/balance updated.');
    }

    /** Shared by store() (immediate path) and markReceived() (deferred path) — must never drift between the two. */
    private function applyEffects(array $data, array $imeis): void
    {
        if ($data['method'] === 'cash' || $data['method'] === 'bank') {
            // Deliberately allowed to go negative rather than clamped to
            // 0 — see ExpenseController for the same reasoning: hiding a
            // shortfall behind a floor makes the ledger stop matching
            // the sum of recorded purchases with no way to detect it.
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
            Shop::whereKey(Tenancy::id())->decrement($field, $data['amount']);
        } elseif ($data['method'] === 'credit' && ! empty($data['supplier_id'])) {
            // Only supplier-linked credit purchases are trackable as a
            // payable that can later be settled (SupplierPaymentController) —
            // a freeform/no-supplier credit purchase has no entity to
            // pay down against, same limitation as before this feature.
            Supplier::whereKey($data['supplier_id'])->increment('payable', $data['amount']);
        }

        if (! empty($data['product_id'])) {
            $lockedProduct = Product::whereKey($data['product_id'])->lockForUpdate()->first();

            if ($lockedProduct->variants()->exists()) {
                abort(422, 'এটি ভ্যারিয়েন্ট পণ্য — এই ক্রয়ে পণ্য যোগ না করে শুধু টাকার হিসাব রাখুন, স্টক প্রতিটি ভ্যারিয়েন্টে আলাদাভাবে যোগ করুন।');
            }

            if (! $lockedProduct->sold_by_weight && floor($data['qty']) != $data['qty']) {
                abort(422, "{$lockedProduct->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
            }

            StockIn::apply($lockedProduct, (float) $data['qty'], $data['cost'] ?? null);

            $shop = Tenancy::shop();
            if ($shop?->hasFeature('batch_tracking') && ! $lockedProduct->sold_by_weight) {
                \App\Support\BatchStock::receive($lockedProduct, (int) $data['qty'], $data['batch_no'] ?? null, $data['expiry_date'] ?? null, $data['cost'] ?? null);
            }
            if ($shop?->hasFeature('serial_tracking') && $imeis) {
                \App\Support\SerialStock::receive($lockedProduct, $imeis, $data['warranty_expiry'] ?? null, $data['cost'] ?? null);
            }
        } elseif (! empty($data['ingredient_id'])) {
            // raw-ingredient purchase -- reuses the same supplier/cash/bank/
            // credit-payable flow above, just a different stock target;
            // cost is a simple latest-cost overwrite, not a weighted average
            $lockedIngredient = \App\Models\Ingredient::whereKey($data['ingredient_id'])->lockForUpdate()->first();
            $lockedIngredient->increment('stock', $data['qty']);
            if (! empty($data['cost'])) {
                $lockedIngredient->update(['cost' => $data['cost']]);
            }
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier' => ['nullable', 'string', 'max:255'],
            // scoped to the current shop — an unscoped `exists` rule queries
            // the raw table, so a different shop's valid supplier/product id
            // would otherwise pass validation and only fail (or, for a
            // product id, throw an uncaught error) once the scoped Eloquent
            // query behind it silently finds nothing
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('shop_id', Tenancy::id())],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,credit'],
            'status' => ['nullable', 'in:pending,received'],
            // optional — present only when this purchase also received
            // stock, so a purchase can still be money-only (e.g. a utility
            // bill) by leaving these out
            'product_id' => ['nullable', 'prohibits:ingredient_id', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'ingredient_id' => ['nullable', Rule::exists('ingredients', 'id')->where('shop_id', Tenancy::id())],
            'qty' => ['nullable', 'numeric', 'min:0.001', 'required_with:product_id,ingredient_id'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'imeis' => ['nullable', 'string'],
            'warranty_expiry' => ['nullable', 'date'],
        ]);
    }
}
