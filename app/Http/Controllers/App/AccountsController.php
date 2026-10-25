<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Support\Tenancy;
use Inertia\Inertia;

class AccountsController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();

        $stockValue = (float) Product::selectRaw('COALESCE(SUM(cost * stock), 0) as v')->value('v');
        $receivable = (float) Customer::sum('due');
        // a delivered online order's COD is money the courier has already
        // collected from the customer but hasn't yet handed to the shop —
        // a real receivable until CourierController::settle() turns it
        // into actual cash_balance (see docs/FIX_PLAN.md's Phase 33 design
        // note). Pending/in-transit orders aren't counted — the courier
        // hasn't collected anything from the customer yet at that point.
        $codReceivable = (float) OnlineOrder::where('status', 'delivered')->whereNull('cod_settled_at')->sum('cod_amount');
        // Supplier::payable settles as SupplierPaymentController pays it
        // down; a credit purchase with no supplier chosen has no entity to
        // settle against, so (like before this feature existed) it stays a
        // permanent payable — summed separately since it's a different
        // running total (Purchase.amount, not Supplier.payable).
        $payable = (float) Supplier::sum('payable')
            + (float) Purchase::whereNull('supplier_id')->where('method', 'credit')->sum('amount');
        $assets = (float) $shop->cash_balance + (float) $shop->bank_balance + $stockValue + $receivable + $codReceivable;
        $liabilities = $payable;
        $netWorth = $assets - $liabilities;

        return Inertia::render('App/Accounts/Index', [
            'shop' => $shop,
            'cash' => (float) $shop->cash_balance,
            'bank' => (float) $shop->bank_balance,
            'stockValue' => $stockValue,
            'receivable' => $receivable,
            'codReceivable' => $codReceivable,
            'payable' => $payable,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'netWorth' => $netWorth,
            'capital' => (float) $shop->capital,
            'retained' => $netWorth - (float) $shop->capital,
            'totalExpenses' => (float) Expense::sum('amount'),
            'totalDamage' => (float) Damage::sum('loss'),
            'totalReturns' => (float) SalesReturn::sum('refund'),
        ]);
    }
}
