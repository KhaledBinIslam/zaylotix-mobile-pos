<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\PreparationItem;
use App\Models\Product;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * "Cooked N units of this dish" -- consumes the recipe's ingredients and, in
 * the same transaction, stocks the finished product IN by that many units.
 * This is the recipe-based counterpart to a normal purchase/stock-in for a
 * product that doesn't need ingredient tracking; products.stock stays the
 * one number every report/alert already reads either way.
 */
class PreparationController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Preparations/Index', [
            'preparations' => Preparation::with('items')->latest()->limit(50)->get(),
            // only products with a configured recipe make sense to prepare
            'products' => Product::whereHas('recipes')->orderBy('name')->get(['id', 'name', 'emoji']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        DB::transaction(function () use ($data) {
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->first();
            $recipes = $product->recipes()->with('ingredient')->orderBy('ingredient_id')->get();

            if ($recipes->isEmpty()) {
                abort(422, "{$product->name}-এর কোনো রেসিপি সেট করা নেই — আগে ইনগ্রেডিয়েন্ট থেকে রেসিপি সেট করুন।");
            }

            $preparation = Preparation::create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'qty' => $data['qty'],
                'created_by' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
            ]);

            foreach ($recipes as $recipe) {
                // locked in ascending ingredient_id order (recipes already
                // sorted that way above) -- same deadlock-avoidance pattern
                // used everywhere else in this codebase that locks multiple
                // rows in one transaction
                $ingredient = Ingredient::whereKey($recipe->ingredient_id)->lockForUpdate()->first();
                $consumed = (float) $recipe->qty_per_unit * (float) $data['qty'];

                $ingredient->decrement('stock', $consumed);

                PreparationItem::create([
                    'preparation_id' => $preparation->id,
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'qty_consumed' => $consumed,
                ]);
            }

            $product->increment('stock', $data['qty']);

            Activity::log('preparation.record', "{$product->name} — {$data['qty']} ইউনিট প্রস্তুত করা হয়েছে।", $preparation);
        });

        return back()->with('success', 'প্রস্তুতকরণ রেকর্ড করা হয়েছে — স্টক আপডেট হয়েছে।');
    }
}
