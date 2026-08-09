<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $todaySales = Sale::whereDate('date', $today)->get();

        return response()->json([
            // never the raw model, see Shop::toArrayForUser()
            'shop' => Tenancy::shop()?->toArrayForUser($request->user()),
            'todaySale' => (float) $todaySales->sum('total'),
            'todayProfit' => (float) $todaySales->sum('profit'),
            'billsToday' => $todaySales->count(),
            'totalDue' => (float) Customer::sum('due'),
            'lowStockCount' => Product::where('stock', '<=', 6)->count(),
            'recentSales' => Sale::with('customer')->latest('id')->limit(10)->get(),
        ]);
    }
}
