<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Sale;
use App\Models\Shop;
use Inertia\Inertia;

/**
 * Platform-wide usage — every query here deliberately uses
 * withoutGlobalScopes() since admin routes have no tenant set (Tenancy::id()
 * is null on the admin guard), and this page's whole point is looking
 * across every shop at once, not one tenant's slice of it.
 */
class AnalyticsController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(6)->toDateString();
        $monthAgo = now()->subDays(29)->toDateString();

        $mostActiveShops = Sale::withoutGlobalScopes()
            ->whereDate('date', '>=', $monthAgo)
            ->whereNull('voided_at')
            ->selectRaw('shop_id, COUNT(*) as sale_count, SUM(total) as total_revenue')
            ->groupBy('shop_id')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $shop = Shop::withoutGlobalScopes()->find($row->shop_id);

                return [
                    'shop_id' => $row->shop_id,
                    'shop_name' => $shop?->name ?? '—',
                    'sale_count' => (int) $row->sale_count,
                    'total_revenue' => round((float) $row->total_revenue, 2),
                ];
            });

        $totalShops = Shop::withoutGlobalScopes()->count();
        $featureAdoption = Feature::where('is_active', true)->orderBy('category')->get()->map(fn (Feature $f) => [
            'key' => $f->key,
            'label' => $f->label_en,
            'category' => $f->category,
            'shop_count' => $f->shops()->count(),
            'percent' => $totalShops ? round($f->shops()->count() / $totalShops * 100, 1) : 0,
        ]);

        // signups per week for the last 8 weeks — a quick growth-trend glance
        $signupTrend = collect(range(7, 0))->map(function ($weeksAgo) {
            $start = now()->subWeeks($weeksAgo)->startOfWeek()->toDateString();
            $end = now()->subWeeks($weeksAgo)->endOfWeek()->toDateString();

            return [
                'week_of' => $start,
                'count' => Shop::withoutGlobalScopes()->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)->count(),
            ];
        });

        $activeShopsToday = Sale::withoutGlobalScopes()->whereDate('date', $today)->whereNull('voided_at')->distinct('shop_id')->count('shop_id');
        $activeShopsWeek = Sale::withoutGlobalScopes()->whereDate('date', '>=', $weekAgo)->whereNull('voided_at')->distinct('shop_id')->count('shop_id');

        return Inertia::render('Admin/Analytics/Index', [
            'stats' => [
                'salesToday' => (float) Sale::withoutGlobalScopes()->whereDate('date', $today)->whereNull('voided_at')->sum('total'),
                'billsToday' => Sale::withoutGlobalScopes()->whereDate('date', $today)->whereNull('voided_at')->count(),
                'salesWeek' => (float) Sale::withoutGlobalScopes()->whereDate('date', '>=', $weekAgo)->whereNull('voided_at')->sum('total'),
                'salesMonth' => (float) Sale::withoutGlobalScopes()->whereDate('date', '>=', $monthAgo)->whereNull('voided_at')->sum('total'),
                'totalShops' => $totalShops,
                'activeShopsToday' => $activeShopsToday,
                'activeShopsWeek' => $activeShopsWeek,
            ],
            'mostActiveShops' => $mostActiveShops,
            'featureAdoption' => $featureAdoption,
            'signupTrend' => $signupTrend,
        ]);
    }
}
