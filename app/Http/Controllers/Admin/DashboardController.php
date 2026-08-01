<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $totalShops = Shop::count();
        $activeShops = Shop::where('status', 'active')->count();
        $inactiveShops = $totalShops - $activeShops;

        $thisMonth = now()->format('Y-m');
        $collectedThisMonth = SubscriptionPayment::where('month', $thisMonth)->sum('amount');

        $paidShopIds = SubscriptionPayment::where('month', $thisMonth)->pluck('shop_id')->unique();
        $unpaidShops = Shop::where('status', 'active')->whereNotIn('id', $paidShopIds)->get(['id', 'name', 'phone']);
        $paidShops = Shop::whereIn('id', $paidShopIds)->get(['id', 'name', 'phone'])
            ->map(function ($shop) use ($thisMonth) {
                $shop->paid_amount = SubscriptionPayment::where('shop_id', $shop->id)->where('month', $thisMonth)->sum('amount');

                return $shop;
            });

        $expiringSoon = Shop::where('status', 'active')
            ->whereNotNull('subscription_expiry')
            ->whereBetween('subscription_expiry', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get(['id', 'name', 'phone', 'subscription_expiry']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalShops' => $totalShops,
                'activeShops' => $activeShops,
                'inactiveShops' => $inactiveShops,
                'collectedThisMonth' => $collectedThisMonth,
                'activeMonthlyShops' => Shop::where('status', 'active')->where('plan', 'monthly')->count(),
                'activeYearlyShops' => Shop::where('status', 'active')->where('plan', 'yearly')->count(),
            ],
            'unpaidShops' => $unpaidShops,
            'paidShops' => $paidShops,
            'expiringSoon' => $expiringSoon,
        ]);
    }
}
