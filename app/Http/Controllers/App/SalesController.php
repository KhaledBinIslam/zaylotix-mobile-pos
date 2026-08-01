<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\SaleReversal;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalesController extends Controller
{
    /** Full sales history: search by invoice number, or filter to only the logged-in staff member's own sales. */
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $mine = $request->boolean('mine');

        // withVoided() — a voided sale still needs to be findable/visible in
        // history (with a badge, see Sales/Index.vue), it just must never
        // count toward revenue anywhere else (Sale's default scope already
        // handles that for every other query in the app).
        $sales = Sale::withVoided()->with(['customer', 'user'])
            ->when($q !== '', fn ($query) => $query->where('invoice_no', 'like', "%{$q}%"))
            ->when($mine, fn ($query) => $query->where('user_id', Auth::guard('web')->id()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('App/Sales/Index', [
            'sales' => $sales,
            'q' => $q,
            'mine' => $mine,
        ]);
    }

    public function show(int $sale)
    {
        // Not implicit route-model-binding — Sale's default scope excludes
        // voided sales, and this page must still be reachable for a voided
        // one (to see the reason/who voided it), so it's resolved manually.
        $saleModel = Sale::withVoided()->findOrFail($sale);
        $this->authorize('view', $saleModel);

        return Inertia::render('App/Sales/Show', [
            // tableOrder (only present when this sale came from billing a
            // restaurant table) carries the kitchen-facing items/note over
            // so this page can offer the combined kitchen+customer
            // print/WhatsApp without a second round trip
            'sale' => $saleModel->load(['items', 'customer', 'user', 'payments', 'voidedByUser', 'tableOrder.table']),
        ]);
    }

    /**
     * Owner-only void — the `owner` middleware on this route (not a
     * delegatable perm: key) IS the manager-approval boundary: a cashier's
     * session can never reach this route at all, matching how staff
     * management is already owner-exclusive. Reverses the exact same
     * stock/balance/due effects the admin's hard-delete tool does (shared
     * via SaleReversal), but marks the row voided instead of deleting it, so
     * the invoice and the reason stay visible in history.
     */
    public function destroy(Request $request, int $sale)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $invoiceNo = DB::transaction(function () use ($sale, $data) {
            $lockedShop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $saleModel = Sale::with(['items', 'payments'])->findOrFail($sale);
            $this->authorize('delete', $saleModel);

            SaleReversal::reverse($saleModel, $lockedShop);

            $saleModel->update([
                'voided_at' => now(),
                'voided_reason' => $data['reason'],
                'voided_by' => Auth::guard('web')->id(),
            ]);

            Activity::log('sale.void', "মেমো '{$saleModel->invoice_no}' বাতিল করা হয়েছে — কারণ: {$data['reason']}", $saleModel, [
                'total' => (float) $saleModel->total,
                'reason' => $data['reason'],
            ]);

            return $saleModel->invoice_no;
        });

        return back()->with('success', "মেমো '{$invoiceNo}' বাতিল করা হয়েছে — স্টক ও ব্যালেন্স ফিরিয়ে দেওয়া হয়েছে।");
    }
}
