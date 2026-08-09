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

        $productsQuery = Product::whereDoesntHave('variants')->orderBy('name');
        // feeds the batch picker on the Damage/Return sheets — every
        // available batch (expired ones included; writing off/returning
        // against an already-expired batch is a legitimate, expected use of
        // both of those flows, unlike a sale) so the cashier can name which
        // physical batch the units actually came from
        if ($shop?->hasFeature('batch_tracking')) {
            $productsQuery->with(['batches' => fn ($q) => $q->available()->fefoOrder()]);
        }

        return Inertia::render('App/More', [
            // More is reachable by any staff regardless of permission
            // grants — never the raw model, see Shop::toArrayForUser()
            'shop' => $shop?->toArrayForUser(Auth::guard('web')->user()),
            // feeds the Purchase/Damage/Return/Stock-count pickers — none of
            // those flows support variant products (their stock is a live
            // sum of variants, managed from the Stock page instead), so
            // variant-having products are excluded here rather than letting
            // one get picked and silently rejected or mishandled downstream
            'products' => $productsQuery->get(['id', 'name', 'emoji', 'price', 'cost', 'stock']),
            'suppliers' => $shop?->hasFeature('suppliers') ? Supplier::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }
}
