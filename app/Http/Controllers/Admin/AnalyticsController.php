<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Support\Facades\Storage;
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
            'shopUsage' => $this->shopUsage(),
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

    /**
     * Per-shop resource footprint — this is a shared-schema multi-tenant
     * app (every shop's rows live in the same tables, not a database per
     * tenant), so there's no per-tenant disk-usage number the OS can just
     * report. Row counts across the biggest tables are the closest real
     * proxy for "how much of the hosting is this shop actually using",
     * plus the one genuinely separate-per-shop disk cost: uploaded files
     * (logo + product photos) on the public storage disk.
     */
    private function shopUsage(): array
    {
        $shops = Shop::withoutGlobalScopes()->get(['id', 'name', 'phone', 'status', 'created_at']);

        $productCounts = Product::withoutGlobalScopes()->selectRaw('shop_id, COUNT(*) as c')->groupBy('shop_id')->pluck('c', 'shop_id');
        $saleCounts = Sale::withoutGlobalScopes()->selectRaw('shop_id, COUNT(*) as c')->groupBy('shop_id')->pluck('c', 'shop_id');
        $saleItemCounts = SaleItem::withoutGlobalScopes()->selectRaw('shop_id, COUNT(*) as c')->groupBy('shop_id')->pluck('c', 'shop_id');
        $customerCounts = Customer::withoutGlobalScopes()->selectRaw('shop_id, COUNT(*) as c')->groupBy('shop_id')->pluck('c', 'shop_id');

        $disk = Storage::disk('public');
        $logoSizes = [];
        foreach (Shop::withoutGlobalScopes()->whereNotNull('logo_path')->pluck('logo_path', 'id') as $shopId => $path) {
            $logoSizes[$shopId] = $disk->exists($path) ? $disk->size($path) : 0;
        }
        // photo files are stored flat (product-photos/{random}.ext, no
        // per-shop subfolder — see ProductController::store()), so the only
        // reliable way to attribute one to a shop is via the DB row that
        // references it, not the file path itself
        $photoBytesByShop = [];
        foreach (Product::withoutGlobalScopes()->whereNotNull('photo_path')->get(['shop_id', 'photo_path']) as $product) {
            if ($disk->exists($product->photo_path)) {
                $photoBytesByShop[$product->shop_id] = ($photoBytesByShop[$product->shop_id] ?? 0) + $disk->size($product->photo_path);
            }
        }

        return $shops->map(function (Shop $shop) use ($productCounts, $saleCounts, $saleItemCounts, $customerCounts, $logoSizes, $photoBytesByShop) {
            $rowCount = ($productCounts[$shop->id] ?? 0) + ($saleCounts[$shop->id] ?? 0) + ($saleItemCounts[$shop->id] ?? 0) + ($customerCounts[$shop->id] ?? 0);
            $storageBytes = ($logoSizes[$shop->id] ?? 0) + ($photoBytesByShop[$shop->id] ?? 0);

            return [
                'id' => $shop->id,
                'name' => $shop->name,
                'phone' => $shop->phone,
                'status' => $shop->status,
                'created_at' => $shop->created_at->toDateString(),
                'product_count' => (int) ($productCounts[$shop->id] ?? 0),
                'sale_count' => (int) ($saleCounts[$shop->id] ?? 0),
                'sale_item_count' => (int) ($saleItemCounts[$shop->id] ?? 0),
                'customer_count' => (int) ($customerCounts[$shop->id] ?? 0),
                'row_count' => $rowCount,
                'storage_mb' => round($storageBytes / 1048576, 2),
            ];
        })->sortByDesc('row_count')->values()->all();
    }
}
