<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * "Business Dashboard" — a company-wide, all-branches overview for a
 * multi-branch shop (Khaled's explicit ask, referencing another product's
 * "All Branches" dashboard): per-branch sales/profit/stock/expenses/due/
 * payable/employees/low-stock, a revenue trend across the whole business,
 * and the business's own most-recent invoices regardless of which branch
 * rang them up. Every number reuses Reports.php's existing formulas
 * (see companyBranchBreakdown()'s own docblock) — no new accounting
 * system, just existing per-shop figures broken out per branch instead
 * of summed into one shop's total. Works for a single-shop business too
 * (the "group" is just that one shop), so no feature flag is needed to
 * gate it off — a single-branch shop simply sees a dashboard with one row.
 */
class CompanyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $shop = Tenancy::shop();
        $root = $shop->parent_shop_id
            ? (Shop::withoutGlobalScopes()->find($shop->parent_shop_id) ?? $shop)
            : $shop;

        $shopIds = Tenancy::businessShopIds($shop->id);

        $preset = $request->get('range', 'month');
        [$from, $to] = match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString()],
        };

        $breakdown = Reports::companyBranchBreakdown($shopIds, $from, $to);

        // last 6 months' company-wide revenue, for the trend chart — one
        // cheap grouped query rather than 6 separate rangeStats() calls
        $trendStart = now()->subMonths(5)->startOfMonth();
        $monthlyRows = Sale::withoutGlobalScopes()->live()->whereIn('shop_id', $shopIds)
            ->where('sale_type', '!=', 'exchange')
            ->whereDate('date', '>=', $trendStart->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, SUM(total) as total")
            ->groupBy('ym')->pluck('total', 'ym');
        $revenueTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $revenueTrend[] = [
                'label' => $month->translatedFormat('M'),
                'total' => round((float) ($monthlyRows->get($month->format('Y-m')) ?? 0), 2),
            ];
        }

        $recentInvoices = Sale::withoutGlobalScopes()->live()->whereIn('shop_id', $shopIds)
            ->where('sale_type', '!=', 'exchange')
            ->with('customer')
            ->latest('id')->limit(10)->get()
            ->map(function (Sale $s) use ($breakdown) {
                $branchName = collect($breakdown)->firstWhere('shop_id', $s->shop_id)['shop_name'] ?? '—';

                return [
                    'id' => $s->id,
                    'invoice_no' => $s->invoice_no,
                    'date' => $s->date->toDateString(),
                    'branch' => $branchName,
                    'customer' => $s->customer?->name,
                    'payment_mode' => $s->payment_mode,
                    'total' => (float) $s->total,
                ];
            });

        return inertia('App/CompanyDashboard/Index', [
            'businessName' => $root->name,
            'range' => $preset,
            'branches' => $breakdown,
            'totals' => [
                'sales_total' => round(collect($breakdown)->sum('sales_total'), 2),
                'profit' => round(collect($breakdown)->sum('profit'), 2),
                'stock_value' => round(collect($breakdown)->sum('stock_value'), 2),
                'expenses_total' => round(collect($breakdown)->sum('expenses_total'), 2),
                'customer_due' => round(collect($breakdown)->sum('customer_due'), 2),
                'supplier_payable' => round(collect($breakdown)->sum('supplier_payable'), 2),
                'employee_count' => (int) collect($breakdown)->sum('employee_count'),
                'low_stock_count' => (int) collect($breakdown)->sum('low_stock_count'),
            ],
            'revenueTrend' => $revenueTrend,
            'recentInvoices' => $recentInvoices,
        ]);
    }
}
