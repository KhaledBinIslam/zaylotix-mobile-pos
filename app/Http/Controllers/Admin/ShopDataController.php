<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\SaleReversal;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Support-tool for the platform admin to remove a single record inside a
 * shop on request (e.g. the shop owner calls/messages asking for a wrong
 * entry to be cleaned up) — distinct from ShopController::destroy, which
 * wipes an entire shop.
 *
 * $product/$customer/$sale are plain IDs, not route-model-bound: Product,
 * Customer and Sale all carry the tenant scope (BelongsToTenant), and
 * implicit binding resolves before this method body runs — at that point
 * the admin guard has no tenant bound yet, so a bound {product} would
 * always 404. Tenancy::set($shop->id) below makes the tenant-scoped lookup
 * resolve correctly instead, which also means a mismatched ID (wrong shop)
 * still 404s exactly like the owner-facing routes do.
 */
class ShopDataController extends Controller
{
    public function destroyProduct(Shop $shop, int $product)
    {
        Tenancy::set($shop->id);
        $productModel = Product::findOrFail($product);
        $productModel->delete(); // soft delete — keeps historical sale_items intact, same as the owner-facing delete
        Tenancy::clear();

        return back()->with('success', "পণ্য '{$productModel->name}' মুছে ফেলা হয়েছে।");
    }

    public function destroyCustomer(Shop $shop, int $customer)
    {
        Tenancy::set($shop->id);
        $customerModel = Customer::findOrFail($customer);
        $customerModel->delete(); // soft delete — sales referencing this customer keep their own snapshot, customer_id just goes null
        Tenancy::clear();

        return back()->with('success', "কাস্টমার '{$customerModel->name}' মুছে ফেলা হয়েছে।");
    }

    /**
     * Deleting a sale isn't just removing the row — it has to undo exactly
     * what checkout did: give the stock back, and roll back whatever it
     * added to cash/bank balance or a customer's due/spend history. Mirrors
     * PosController::checkout's locking discipline so this can't race a
     * live sale on the same product/customer/shop row.
     */
    public function destroySale(Shop $shop, int $sale)
    {
        $invoiceNo = DB::transaction(function () use ($shop, $sale) {
            Tenancy::set($shop->id);

            $lockedShop = Shop::whereKey($shop->id)->lockForUpdate()->first();
            $saleModel = Sale::withVoided()->with(['items', 'payments'])->findOrFail($sale);

            // withVoided() is needed above so an already-voided sale can still
            // be found and hard-deleted, but its stock/balance/due were already
            // given back once at void time — reversing again here would give
            // it back a second time
            if (! $saleModel->isVoided()) {
                SaleReversal::reverse($saleModel, $lockedShop);
            }

            $invoiceNo = $saleModel->invoice_no;
            $saleModel->delete(); // sale_items/sale_payments cascade-delete with it (FK, not soft-deleted)

            Tenancy::clear();

            return $invoiceNo;
        });

        return back()->with('success', "মেমো '{$invoiceNo}' মুছে ফেলা হয়েছে — স্টক ও ব্যালেন্স ফিরিয়ে দেওয়া হয়েছে।");
    }
}
