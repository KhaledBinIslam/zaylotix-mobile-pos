<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MoreController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();

        $productsQuery = Product::orderBy('name');
        // feeds the batch picker on the Damage/Return sheets — every
        // available batch (expired ones included; writing off/returning
        // against an already-expired batch is a legitimate, expected use of
        // both of those flows, unlike a sale) so the cashier can name which
        // physical batch the units actually came from
        if ($shop?->hasFeature('batch_tracking')) {
            $productsQuery->with(['batches' => fn ($q) => $q->available()->fefoOrder()]);
        }
        // feeds the variant picker on the Damage/Stock-count sheets — a
        // variant product's own `stock` is never writable directly there
        // (DamageController/StockCountController both require naming the
        // specific size/color instead), same reasoning Purchase/Stock-in
        // already follow
        if ($shop?->hasFeature('product_variants')) {
            $productsQuery->with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')]);
        }

        return Inertia::render('App/More', [
            // More is reachable by any staff regardless of permission
            // grants — never the raw model, see Shop::toArrayForUser()
            'shop' => $shop?->toArrayForUser(Auth::guard('web')->user()),
            // feeds the Purchase/Damage/Return/Stock-count pickers — the
            // quick Return sheet here still only takes a plain product
            // (unchanged; a variant return goes through the full Returns
            // page instead, which already handles it), but Damage and
            // Stock-count now take a specific variant when the product has
            // any (see those controllers)
            'products' => $productsQuery->get(['id', 'name', 'emoji', 'price', 'cost', 'stock']),
            'suppliers' => $shop?->hasFeature('suppliers') ? Supplier::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }
}
