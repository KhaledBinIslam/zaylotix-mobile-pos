<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Demo/trial/internal shops are real rows (they log in, sell, show up
     * everywhere else) but aren't the paid book of business — every figure
     * on this page is scoped to paidAccounts() so a seeded demo shop or an
     * in-progress trial signup never inflates the numbers Khaled actually
     * runs the business on.
     */
    public function index()
    {
        $totalShops = Shop::paidAccounts()->count();
        $activeShops = Shop::paidAccounts()->where('status', 'active')->count();
        $inactiveShops = $totalShops - $activeShops;

        $thisMonth = now()->format('Y-m');
        $paidAccountShopIds = Shop::paidAccounts()->pluck('id');
        $collectedThisMonth = SubscriptionPayment::whereIn('shop_id', $paidAccountShopIds)->where('month', $thisMonth)->sum('amount');

        $paidShopIds = SubscriptionPayment::whereIn('shop_id', $paidAccountShopIds)->where('month', $thisMonth)->pluck('shop_id')->unique();
        $unpaidShops = Shop::paidAccounts()->where('status', 'active')->whereNotIn('id', $paidShopIds)->get(['id', 'name', 'phone']);
        $paidShops = Shop::paidAccounts()->whereIn('id', $paidShopIds)->get(['id', 'name', 'phone'])
            ->map(function ($shop) use ($thisMonth) {
                $shop->paid_amount = SubscriptionPayment::where('shop_id', $shop->id)->where('month', $thisMonth)->sum('amount');

                return $shop;
            });

        $expiringSoon = Shop::paidAccounts()->where('status', 'active')
            ->whereNotNull('subscription_expiry')
            ->whereBetween('subscription_expiry', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get(['id', 'name', 'phone', 'subscription_expiry']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalShops' => $totalShops,
                'activeShops' => $activeShops,
                'inactiveShops' => $inactiveShops,
                'collectedThisMonth' => $collectedThisMonth,
                'activeMonthlyShops' => Shop::paidAccounts()->where('status', 'active')->where('plan', 'monthly')->count(),
                'activeYearlyShops' => Shop::paidAccounts()->where('status', 'active')->where('plan', 'yearly')->count(),
            ],
            'unpaidShops' => $unpaidShops,
            'paidShops' => $paidShops,
            'expiringSoon' => $expiringSoon,
        ]);
    }
}
