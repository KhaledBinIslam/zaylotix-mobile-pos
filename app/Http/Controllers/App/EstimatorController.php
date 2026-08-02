<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Pure computation, nothing persisted -- "if I plan to sell these dishes in these quantities, how much of each ingredient do I need" for planning purchases ahead of a busy day/event. */
class EstimatorController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Estimator/Index', [
            'products' => Product::whereHas('recipes')->orderBy('name')->get(['id', 'name', 'emoji']),
        ]);
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $totals = [];
        foreach ($data['lines'] as $line) {
            $recipes = ProductRecipe::where('product_id', $line['product_id'])->with('ingredient:id,name,unit')->get();
            foreach ($recipes as $recipe) {
                if (! $recipe->ingredient) {
                    continue;
                }
                $key = $recipe->ingredient_id;
                $totals[$key] ??= ['name' => $recipe->ingredient->name, 'unit' => $recipe->ingredient->unit, 'qty' => 0.0];
                $totals[$key]['qty'] += (float) $recipe->qty_per_unit * (float) $line['qty'];
            }
        }

        return response()->json(['totals' => array_values($totals)]);
    }
}
