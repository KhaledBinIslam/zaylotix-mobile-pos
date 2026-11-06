<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Tenancy;
use Inertia\Inertia;

/**
 * Brand management, SEAMAN Phase 4 (feature: brands). Add / rename /
 * merge / delete. Merge exists because a hand-transcribed catalogue
 * breeds near-duplicates ("Safeguard" vs "Safe Guard") — merging points
 * every product and variant at the survivor and deletes the duplicate,
 * in one transaction. Deleting asks nothing of products: their brand
 * simply becomes "Nil" (FK nullOnDelete), which is the owner's stated
 * display rule for anything missing.
 */
class BrandController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Brands/Index', [
            'brands' => Brand::withCount(['products', 'variants'])->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'products_count' => $b->products_count,
                    'variants_count' => $b->variants_count,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->where('shop_id', Tenancy::id())],
        ], ['name.unique' => 'এই নামে ব্র্যান্ড আগেই আছে।']);

        Brand::create(['name' => trim($data['name'])]);

        return back()->with('success', 'ব্র্যান্ড তৈরি হয়েছে।');
    }

    public function update(Request $request, Brand $brand)
    {
        abort_unless($brand->shop_id === Tenancy::id(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->where('shop_id', Tenancy::id())->ignore($brand->id)],
        ], ['name.unique' => 'এই নামে ব্র্যান্ড আগেই আছে।']);

        $brand->update(['name' => trim($data['name'])]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    /** Point everything at the survivor, delete the duplicate — one transaction, nothing half-merged. */
    public function merge(Request $request, Brand $brand)
    {
        abort_unless($brand->shop_id === Tenancy::id(), 404);

        $data = $request->validate(['into' => ['required', 'integer']]);

        $survivor = Brand::find($data['into']); // tenant-scoped
        if (! $survivor || $survivor->id === $brand->id) {
            return back()->withErrors(['into' => 'যে ব্র্যান্ডে মেশাতে বলা হয়েছে সেটা পাওয়া যায়নি।']);
        }

        DB::transaction(function () use ($brand, $survivor) {
            Product::where('brand_id', $brand->id)->update(['brand_id' => $survivor->id]);
            ProductVariant::where('brand_id', $brand->id)->update(['brand_id' => $survivor->id]);
            $brand->delete();
        });

        Activity::log('brand.merge', "ব্র্যান্ড '{$brand->name}' মিশে গেল '{$survivor->name}'-এ।", $survivor);

        return back()->with('success', "'{$brand->name}' মিশে গেছে '{$survivor->name}'-এ।");
    }

    public function destroy(Brand $brand)
    {
        abort_unless($brand->shop_id === Tenancy::id(), 404);

        // products/variants keep living; their brand becomes "Nil" via the
        // FK's own nullOnDelete — stated to the owner in the confirm text
        $brand->delete();

        Activity::log('brand.delete', "ব্র্যান্ড '{$brand->name}' মুছে ফেলা হয়েছে।", $brand);

        return back()->with('success', 'ব্র্যান্ড মুছে ফেলা হয়েছে — পণ্যগুলোর ব্র্যান্ড এখন Nil।');
    }
}
