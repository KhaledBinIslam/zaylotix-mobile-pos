<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * A standalone "manage my shop's units" screen — before this, the only way
 * to create a unit at all was buried inside a product's own create/edit
 * form (ProductController::store/update's new_unit_name, and
 * ProductUnitController::store's own new_unit_name for a pack size), which
 * Khaled reported as genuinely undiscoverable: a shop owner had no page to
 * go to just to see/manage their units, only a side-effect of editing some
 * specific product. Those existing inline "or type a new unit" fields are
 * untouched (still work exactly as before, for someone already mid-way
 * through setting up a product) — this is purely a new, additional, easier
 * front door onto the exact same `units` table.
 */
class UnitController extends Controller
{
    public function index()
    {
        $shopId = Tenancy::id();

        $units = Unit::orderBy('name')->get()->map(function (Unit $unit) use ($shopId) {
            $productCount = Product::where('unit_id', $unit->id)->count();
            $packCount = ProductUnit::where('unit_id', $unit->id)->count();

            return [
                'id' => $unit->id,
                'name' => $unit->name,
                'name_en' => $unit->name_en,
                'code' => $unit->code,
                'in_use' => $productCount + $packCount,
            ];
        });

        return Inertia::render('App/Units/Index', ['units' => $units]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        Unit::create([
            'shop_id' => Tenancy::id(),
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?: $data['name'],
            'code' => $data['code'] ?? null,
        ]);

        return back()->with('success', 'ইউনিট তৈরি হয়েছে।');
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorize('update', $unit);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $unit->update([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?: $data['name'],
            'code' => $data['code'] ?? null,
        ]);

        return back()->with('success', 'ইউনিট আপডেট হয়েছে।');
    }

    /**
     * Refuses to delete a unit that's still actually in use — as either a
     * product's own base unit, or a pack-size's unit (e.g. "Box") — since
     * either reference would be silently left dangling (unit_id pointing
     * at a row that no longer exists) otherwise. Same "never trust this is
     * safe, always re-check" discipline as every other delete guard in
     * this app.
     */
    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);

        $productCount = Product::where('unit_id', $unit->id)->count();
        $packCount = ProductUnit::where('unit_id', $unit->id)->count();

        if ($productCount + $packCount > 0) {
            return back()->withErrors([
                'unit' => "'{$unit->name}' এখনো {$productCount} টি পণ্য ও {$packCount} টি প্যাক সাইজে ব্যবহৃত হচ্ছে — আগে সেগুলো থেকে সরান বা অন্য ইউনিটে বদলান।",
            ]);
        }

        $unit->delete();

        return back()->with('success', 'ইউনিট মুছে ফেলা হয়েছে।');
    }
}
