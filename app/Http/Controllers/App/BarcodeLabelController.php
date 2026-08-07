<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Tenancy;
use Inertia\Inertia;

class BarcodeLabelController extends Controller
{
    /** Product picker for printing barcode labels (shop name, optionally regular/discount price — see priceMode in BarcodeLabels/Index.vue) on a barcode printer. */
    public function index()
    {
        return Inertia::render('App/BarcodeLabels/Index', [
            'shop' => Tenancy::shop(),
            // explicit column list — `barcode_labels` can be granted to a
            // cashier independently of `stock`, and an unscoped get() would
            // ship every column (including cost/margin) to that client
            // regardless of what the template chooses to render
            'products' => Product::whereNotNull('barcode')->where('barcode', '!=', '')
                ->orderBy('name')
                ->get(['id', 'name', 'name_en', 'emoji', 'barcode', 'price', 'discount_price']),
        ]);
    }
}
