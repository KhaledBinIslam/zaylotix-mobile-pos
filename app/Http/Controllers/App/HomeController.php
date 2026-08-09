<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();

        // Only the owner sees the first-run wizard — a cashier logging in
        // for the first time on a shop the owner already set up shouldn't
        // be walked through "how to use this app" as if it were new to them.
        if (Auth::guard('web')->user()?->role === 'owner' && ! $shop?->onboarded_at) {
            return redirect()->route('app.onboarding');
        }

        $today = now()->toDateString();
        $weekAgo = now()->subDays(6)->toDateString();
        $monthAgo = now()->subDays(29)->toDateString();

        $todaySales = Sale::whereDate('date', $today)->get();
        $weekSales = Sale::whereDate('date', '>=', $weekAgo)->get();
        $monthSales = Sale::whereDate('date', '>=', $monthAgo)->get();
        $lowStock = Product::where('stock', '>', 0)->where('stock', '<=', 6)->count();
        $outOfStock = Product::where('stock', 0)->count();

        $recentSales = Sale::with('customer')->latest('id')->limit(4)->get();
        $topDue = Customer::where('due', '>', 0)->orderByDesc('due')->limit(3)->get();

        // best-sellers over the last 30 days, falling back to all-time if the
        // shop is too new to have a month of history yet
        $bestSellers = SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $monthAgo))
            ->selectRaw('product_id, product_name, SUM(qty) as total_qty, SUM(qty * price) as total_revenue')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        if ($bestSellers->isEmpty()) {
            $bestSellers = SaleItem::selectRaw('product_id, product_name, SUM(qty) as total_qty, SUM(qty * price) as total_revenue')
                ->groupBy('product_id', 'product_name')
                ->orderByDesc('total_qty')
                ->limit(5)
                ->get();
        }

        return Inertia::render('App/Home', [
            // shop is reachable by any staff regardless of permission grants
            // — never the raw model, see Shop::toArrayForUser()
            'shop' => $shop?->toArrayForUser(Auth::guard('web')->user()),
            'todaySale' => (float) $todaySales->sum('total'),
            'todayProfit' => (float) $todaySales->sum('profit'),
            'billsToday' => $todaySales->count(),
            'weekSale' => (float) $weekSales->sum('total'),
            'weekProfit' => (float) $weekSales->sum('profit'),
            'monthSale' => (float) $monthSales->sum('total'),
            'monthProfit' => (float) $monthSales->sum('profit'),
            'totalDue' => (float) Customer::sum('due'),
            'dueCustomerCount' => Customer::where('due', '>', 0)->count(),
            'lowStockCount' => $lowStock + $outOfStock,
            'outOfStockCount' => $outOfStock,
            'productCount' => Product::count(),
            'customerCount' => Customer::count(),
            'recentSales' => $recentSales,
            'topDue' => $topDue,
            'bestSellers' => $bestSellers,
        ]);
    }
}
