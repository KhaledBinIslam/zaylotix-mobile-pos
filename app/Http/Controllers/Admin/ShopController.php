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
            // only a main shop (parent_shop_id null) can have branches of
            // its own -- a branch's edit page just never shows this section
            'branches' => $shop->parent_shop_id ? null : $shop->branches()->get(['id', 'name', 'area', 'status', 'created_at']),
        ]);
    }

    public function update(UpdateShopRequest $request, Shop $shop)
    {
        $data = $request->validated();
        $featureKeys = $data['features'] ?? [];
        unset($data['features']);

        // same "Active should actually mean active" fix as toggleStatus()
        // — picking Active in this form's dropdown without also moving the
        // expiry date saves fine but the shop keeps showing Inactive,
        // since Shop::isActive() checks both
        if (($data['status'] ?? $shop->status) === 'active') {
            $expiry = $data['subscription_expiry'] ?? $shop->subscription_expiry;
            if (! $expiry || \Illuminate\Support\Carbon::parse($expiry)->isPast()) {
                $data['subscription_expiry'] = now()->addMonth()->toDateString();
            }
        }

        $shop->update($data);

        $ids = Feature::whereIn('key', $featureKeys)->pluck('id');
        $shop->features()->sync($ids);

        AdminActivity::log('shop.update', "Updated shop '{$shop->name}'.", $shop);

        return redirect()->route('admin.shops.index')->with('success', 'Shop updated.');
    }

    /**
     * Admin-only, per Khaled's explicit constraint — a shop owner can never
     * self-create a branch. Reuses the SAME owner (no new login/user row);
     * the owner reaches it afterward via the branch switcher (see
     * BranchController). Clones the parent's current Product/ProductCategory/
     * Unit rows into the new branch's own independent rows (own stock column,
     * starts at 0 — see the migration/plan note on why this isn't a shared
     * row) using the exact Tenancy::set() pattern ShopProvisioner already
     * uses to seed a brand-new shop's categories/units.
     */
    public function createBranch(Request $request, Shop $shop)
    {
        abort_if($shop->parent_shop_id, 422, 'একটি শাখার অধীনে আরেকটি শাখা তৈরি করা যাবে না।');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'plan' => ['required', 'string'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'subscription_start' => ['required', 'date'],
            'subscription_expiry' => ['required', 'date'],
        ]);

        $branch = DB::transaction(function () use ($data, $shop) {
            $branch = Shop::create([
                ...$data,
                'parent_shop_id' => $shop->id,
                'business_type_id' => $shop->business_type_id,
                'name_en' => $data['name'],
                'owner_name' => $shop->owner_name,
                'sales_mode' => $shop->sales_mode,
                'lang' => $shop->lang,
                'status' => 'active',
            ]);

            $branch->features()->sync($shop->features()->pluck('features.id'));

            // base fields only for this first cut -- variants/batches/serials
            // don't carry over; a branch starts at 0 stock and is stocked
            // in physically, same as any brand-new shop's onboarding
            \App\Support\CatalogSync::syncToBranch($shop, $branch);

            return $branch;
        });

        AdminActivity::log('shop.createBranch', "Created branch '{$branch->name}' under '{$shop->name}'.", $branch);

        return back()->with('success', "শাখা '{$branch->name}' তৈরি হয়েছে।");
    }

    public function toggleStatus(Shop $shop)
    {
        $newStatus = $shop->status === 'active' ? 'inactive' : 'active';
        $update = ['status' => $newStatus];

        // Shop::isActive() (what the list's Active/Inactive pill and every
        // login/subscription check actually read) requires BOTH status
        // and a future subscription_expiry — a shop whose trial/plan
        // already ran out otherwise keeps showing Inactive right after
        // clicking "Activate", with nothing explaining why the button
        // appeared to do nothing. Push expiry forward too whenever
        // activating would otherwise still leave it expired.
        if ($newStatus === 'active' && (! $shop->subscription_expiry || $shop->subscription_expiry->isPast())) {
            $update['subscription_expiry'] = now()->addMonth()->toDateString();
        }

        $shop->update($update);

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
