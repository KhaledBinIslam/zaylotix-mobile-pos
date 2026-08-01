<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductUnitController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('shop_id', Tenancy::id())],
            'new_unit_name' => ['nullable', 'string', 'max:255'],
            'factor' => ['required', 'integer', 'min:2'], // must be a real multi-unit pack, not a 1:1 duplicate
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $unitId = $data['unit_id'] ?? null;
        if (! $unitId && ! empty($data['new_unit_name'])) {
            $unitId = Unit::create([
                'shop_id' => Tenancy::id(),
                'name' => $data['new_unit_name'],
                'name_en' => $data['new_unit_name'],
                'code' => null,
            ])->id;
        }

        if (! $unitId) {
            return back()->withErrors(['unit_id' => 'একটি একক বাছাই করুন বা নতুন এককের নাম দিন']);
        }

        // a pack-size's `factor` is a whole-number multiple of base stock —
        // meaningless for a product whose base stock is already a fractional
        // kg/litre quantity, so the two features stay mutually exclusive
        if ($product->sold_by_weight) {
            return back()->withErrors(['unit_id' => "{$product->name} ওজন/লিটার হিসেবে বিক্রি হয় — এতে প্যাক সাইজ যোগ করা যাবে না।"]);
        }

        ProductUnit::updateOrCreate(
            ['product_id' => $product->id, 'unit_id' => $unitId],
            ['factor' => $data['factor'], 'price' => $data['price']]
        );

        return back()->with('success', 'প্যাক সাইজ যোগ হয়েছে');
    }

    public function destroy(ProductUnit $productUnit)
    {
        $this->authorize('delete', $productUnit);
        $productUnit->delete();

        return back()->with('success', 'মুছে ফেলা হয়েছে');
    }
}
