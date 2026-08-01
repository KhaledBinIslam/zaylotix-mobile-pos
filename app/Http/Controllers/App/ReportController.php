<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\Supplier;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $preset = $request->get('preset', 'week');
        [$from, $to] = $this->resolveRange($preset, $request->get('from'), $request->get('to'));

        $stats = Reports::rangeStats($from, $to);
        $shop = Tenancy::shop();

        return Inertia::render('App/Reports/Index', [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'stats' => $stats,
            'sales' => Sale::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->latest('id')->limit(50)->get(),
            'topProducts' => Reports::topProducts($from, $to),
            'bottomProducts' => Reports::bottomProducts($from, $to),
            'cashierBreakdown' => Reports::cashierCashBreakdown($from, $to),
            // a distinct, browsable list of what's coming due (next 60 days)
            // — the daily alert notification tells the owner *that* something
            // is expiring soon; this is where they see the full picture at once
            'expiringSoon' => $shop?->hasFeature('batch_tracking')
                ? ProductBatch::with('product:id,name,emoji')
                    ->available()
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', now()->addDays(60))
                    ->fefoOrder()
                    ->limit(50)
                    ->get(['id', 'product_id', 'batch_no', 'expiry_date', 'qty'])
                : null,
            'restaurantBreakdown' => $shop?->hasFeature('restaurant_tables') ? Reports::restaurantBreakdown($from, $to) : null,
            'salesByType' => $shop?->hasFeature('wholesale_pricing') ? Reports::salesByType($from, $to) : null,
            'itemWisePurchases' => $shop?->hasFeature('purchases') ? Reports::itemWisePurchases($from, $to) : null,
            'categoryReport' => Reports::categoryReport($from, $to),
            'discountReport' => Reports::discountReport($from, $to),
            'wastageReport' => $shop?->hasFeature('damages') ? Reports::wastageReport($from, $to) : null,
            'ratingReport' => Reports::ratingReport($from, $to),
            'heatmap' => Reports::heatmap($from, $to),
            // one-click "everything" summary — customer due and supplier
            // payable totals alongside the P&L already in $stats, so an
            // owner never has to visit three separate pages to see the
            // whole picture of where the business stands right now
            'summary' => [
                'customer_due_total' => round((float) Customer::sum('due'), 2),
                'customer_due_count' => Customer::where('due', '>', 0)->count(),
                'supplier_payable_total' => $shop?->hasFeature('suppliers') ? round((float) Supplier::sum('payable'), 2) : null,
                'supplier_payable_count' => $shop?->hasFeature('suppliers') ? Supplier::where('payable', '>', 0)->count() : null,
            ],
        ]);
    }

    private function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        return match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            'custom' => [$from ?: now()->subDays(6)->toDateString(), $to ?: now()->toDateString()],
            default => [now()->subDays(6)->toDateString(), now()->toDateString()], // week
        };
    }
}
