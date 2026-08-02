<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IngredientController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Ingredients/Index', [
            'ingredients' => Ingredient::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(['id', 'name', 'emoji']),
            'recipes' => ProductRecipe::with('ingredient:id,name,unit')->get()
                ->groupBy('product_id')
                ->map(fn ($group) => $group->map(fn (ProductRecipe $r) => [
                    'id' => $r->id, 'ingredient_id' => $r->ingredient_id,
                    'ingredient_name' => $r->ingredient?->name, 'unit' => $r->ingredient?->unit,
                    'qty_per_unit' => $r->qty_per_unit,
                ])->values()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
        ]);

        Ingredient::create($data + ['stock' => $data['stock'] ?? 0, 'cost' => $data['cost'] ?? 0]);

        return back()->with('success', 'উপাদান যোগ করা হয়েছে।');
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ingredient->update($data);

        return back()->with('success', 'উপাদান আপডেট হয়েছে।');
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();

        return back()->with('success', 'উপাদান মুছে ফেলা হয়েছে।');
    }

    /** Wholesale-replaces a product's recipe -- simpler and safer than diffing individual rows for what's normally a short, rarely-changed list. */
    public function saveRecipe(Request $request, Product $product)
    {
        $data = $request->validate([
            'lines' => ['array'],
            'lines.*.ingredient_id' => ['required', 'exists:ingredients,id'],
            'lines.*.qty_per_unit' => ['required', 'numeric', 'min:0.001'],
        ]);

        DB::transaction(function () use ($data, $product) {
            $product->recipes()->delete();
            foreach ($data['lines'] ?? [] as $line) {
                ProductRecipe::create([
                    'product_id' => $product->id,
                    'ingredient_id' => $line['ingredient_id'],
                    'qty_per_unit' => $line['qty_per_unit'],
                ]);
            }
        });

        return back()->with('success', 'রেসিপি সংরক্ষণ করা হয়েছে।');
    }
}
