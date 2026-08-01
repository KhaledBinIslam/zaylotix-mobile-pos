<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreShopRequest;
use App\Http\Requests\Admin\UpdateShopRequest;
use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Shop;
use App\Support\AdminActivity;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ShopController extends Controller
{
    public function index()
    {
        $shops = Shop::with('businessType')
            ->withCount(['users'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Shop $shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'phone' => $shop->phone,
                'business_type' => $shop->businessType?->label_bn,
                'plan' => $shop->plan,
                'monthly_fee' => (float) $shop->monthly_fee,
                'status' => $shop->status,
                'sales_mode' => $shop->sales_mode,
                'subscription_expiry' => $shop->subscription_expiry?->toDateString(),
                'days_left' => $shop->daysLeft(),
                'is_active' => $shop->isActive(),
                'created_at' => $shop->created_at->toDateString(),
            ]);

        return Inertia::render('Admin/Shops/Index', ['shops' => $shops]);
    }

    public function create()
    {
        $businessTypes = BusinessType::where('is_active', true)->get()->map(fn ($t) => [
            ...$t->toArray(),
            'default_features' => \App\Support\FeatureRecommendations::forBusinessType($t->slug),
        ]);

        return Inertia::render('Admin/Shops/Create', [
            'businessTypes' => $businessTypes,
            'features' => Feature::where('is_active', true)->orderBy('category')->get(),
        ]);
    }

    public function store(StoreShopRequest $request)
    {
        $data = $request->validated();

        $shop = ShopProvisioner::provision(
            shopAttrs: [
                'business_type_id' => $data['business_type_id'],
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? $data['name'],
                'phone' => $data['phone'],
                'area' => $data['area'] ?? null,
                'owner_name' => $data['owner_name'] ?? null,
                'sales_mode' => $data['sales_mode'],
                'lang' => $data['lang'],
                'plan' => $data['plan'],
                'monthly_fee' => $data['monthly_fee'] ?? null,
                'status' => 'active',
                'subscription_start' => $data['subscription_start'],
                'subscription_expiry' => $data['subscription_expiry'],
            ],
            ownerName: $data['owner_name'] ?? $data['name'],
            ownerPhone: $data['phone'],
            ownerEmail: null,
            ownerPassword: $data['owner_password'],
            featureKeys: $data['features'] ?? [],
        );

        AdminActivity::log('shop.create', "Created shop '{$shop->name}'.", $shop);

        return redirect()->route('admin.shops.index')->with('success', "Shop '{$shop->name}' created.");
    }

    public function edit(Shop $shop)
    {
        $businessTypes = BusinessType::where('is_active', true)->get()->map(fn ($t) => [
            ...$t->toArray(),
            'default_features' => \App\Support\FeatureRecommendations::forBusinessType($t->slug),
        ]);

        return Inertia::render('Admin/Shops/Edit', [
            'shop' => $shop,
            'businessTypes' => $businessTypes,
            'features' => Feature::where('is_active', true)->orderBy('category')->get(),
            'shopFeatureKeys' => $shop->featureKeys(),
        ]);
    }

    public function update(UpdateShopRequest $request, Shop $shop)
    {
        $data = $request->validated();
        $featureKeys = $data['features'] ?? [];
        unset($data['features']);

        $shop->update($data);

        $ids = Feature::whereIn('key', $featureKeys)->pluck('id');
        $shop->features()->sync($ids);

        AdminActivity::log('shop.update', "Updated shop '{$shop->name}'.", $shop);

        return redirect()->route('admin.shops.index')->with('success', 'Shop updated.');
    }

    public function toggleStatus(Shop $shop)
    {
        $shop->update(['status' => $shop->status === 'active' ? 'inactive' : 'active']);

        AdminActivity::log('shop.toggleStatus', "Shop '{$shop->name}' is now {$shop->status}.", $shop);

        return back()->with('success', "Shop is now {$shop->status}.");
    }

    /** Read-only audit view, plus per-record delete actions for support requests ("clean up this one entry"). */
    public function show(Shop $shop)
    {
        Tenancy::set($shop->id);

        $data = [
            'shop' => $shop->load('businessType', 'features'),
            'productCount' => \App\Models\Product::count(),
            'customerCount' => \App\Models\Customer::count(),
            'totalDue' => \App\Models\Customer::sum('due'),
            'recentSales' => \App\Models\Sale::with('customer')->latest('id')->limit(20)->get(),
            'products' => \App\Models\Product::orderBy('name')->get(['id', 'name', 'stock', 'price']),
            'customers' => \App\Models\Customer::orderBy('name')->get(['id', 'name', 'phone', 'due']),
        ];

        Tenancy::clear();

        return Inertia::render('Admin/Shops/Show', $data);
    }

    /**
     * Permanently wipes a shop and every row that references it — every
     * shop_id foreign key in the schema is ON DELETE CASCADE specifically
     * for this, so one delete on the shop row is genuinely complete, not a
     * partial cleanup that leaves orphaned sales/products behind.
     * Irreversible, so the admin has to type the shop's exact name to confirm.
     */
    public function destroy(Request $request, Shop $shop)
    {
        $request->validate(['confirm_name' => ['required', 'string']]);

        if ($request->string('confirm_name')->trim()->value() !== $shop->name) {
            throw ValidationException::withMessages([
                'confirm_name' => 'দোকানের নাম মিলছে না — ঠিক নাম লিখুন।',
            ]);
        }

        $name = $shop->name;

        // notifications and personal_access_tokens are polymorphic
        // (notifiable_type/tokenable_type) with no FK to users, so the
        // cascade-delete on users.shop_id never touches them — without this
        // they'd be orphaned rows referencing a user id that no longer
        // exists, invisible but permanent.
        $userIds = $shop->users()->pluck('id');
        if ($userIds->isNotEmpty()) {
            DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $userIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $userIds)->delete();
        }

        if ($shop->logo_path) {
            Storage::disk('public')->delete($shop->logo_path);
        }

        $shop->delete();

        AdminActivity::log('shop.delete', "Permanently deleted shop '{$name}' and all its data.");

        return redirect()->route('admin.shops.index')->with('success', "শপ '{$name}' এবং এর সমস্ত ডেটা স্থায়ীভাবে মুছে ফেলা হয়েছে।");
    }
}
