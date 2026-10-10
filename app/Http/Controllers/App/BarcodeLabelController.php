<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class BarcodeLabelController extends Controller
{
    /** Product picker for printing barcode labels (shop name, optionally regular/discount price — see priceMode in BarcodeLabels/Index.vue) on a barcode printer. */
    public function index()
    {
        return Inertia::render('App/BarcodeLabels/Index', [
            // never the raw model, see Shop::toArrayForUser()
            'shop' => Tenancy::shop()?->toArrayForUser(Auth::guard('web')->user()),
            // explicit column list — `barcode_labels` can be granted to a
            // cashier independently of `stock`, and an unscoped get() would
            // ship every column (including cost/margin) to that client
            // regardless of what the template chooses to render.
            //
            // A variant product normally carries NO barcode of its own on
            // this row (see DemoShopSeeder's clothingDemo()) — each of its
            // variants has its own instead — so it must be included here
            // whenever it HAS variants, not just when its own `barcode`
            // column happens to be set, or every clothing/shoe shop's whole
            // variant catalog was simply invisible on this page.
            'products' => Product::where(function ($q) {
                $q->whereNotNull('barcode')->where('barcode', '!=', '')
                    ->orWhereHas('variants');
            })
                ->orderBy('name')
                ->with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
                ->get(['id', 'name', 'name_en', 'emoji', 'barcode', 'price', 'discount_price'])
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'name_en' => $p->name_en, 'emoji' => $p->emoji,
                    'barcode' => $p->barcode, 'price' => $p->price, 'discount_price' => $p->discount_price,
                    'variants' => $p->variants->map(fn ($v) => [
                        'id' => $v->id, 'size' => $v->size, 'color' => $v->color,
                        'barcode' => $v->barcode, 'price' => $v->price, 'stock' => $v->stock,
                    ]),
                ]),
        ]);
    }

    /**
     * Assigns a fresh, unique barcode to a variant that doesn't have one
     * yet, so it can be printed right away without a separate trip to the
     * Stock page first. `'ZV' . $variant->id` is unique by construction —
     * variant ids are a single global auto-increment column, never reused
     * across shops or after a delete — so there's nothing to check or
     * retry on collision, unlike a randomly generated code would need.
     */
    public function generateVariantBarcode(ProductVariant $productVariant)
    {
        if (! $productVariant->barcode) {
            $productVariant->update(['barcode' => 'ZV'.$productVariant->id]);
        }

        return back();
    }
}
