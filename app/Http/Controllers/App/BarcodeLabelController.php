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
        $shop = Tenancy::shop();
        // Once a shop has turned "বারকোডে সাইজও যোগ করুন" on, a scan must
        // always resolve straight to one exact size+color — never leave the
        // ambiguous "one sticker per color" choice sitting next to it, or a
        // cashier can still pick it by accident and recreate the exact
        // "scan, then pick a size" problem the setting exists to remove.
        // Below (off) this is unchanged — every color_barcodes consumer
        // keeps seeing it exactly as before.
        $includeSize = $shop?->barcodeIncludeSize() ?? false;

        return Inertia::render('App/BarcodeLabels/Index', [
            // never the raw model, see Shop::toArrayForUser() — carries
            // label_settings (already defaults-merged) alongside shop.name/logo_url
            'shop' => Tenancy::shop()?->toArrayForUser(Auth::guard('web')->user()),
            // explicit column list — `barcode_labels` can be granted to a
            // cashier independently of `stock`, and an unscoped get() would
            // ship every column (including cost/margin) to that client
            // regardless of what the template chooses to render.
            //
            // Live bug fix: this used to only list a product that ALREADY
            // had a barcode (or variants, each carrying its own). But the
            // whole point of this page for a shop that never imported
            // pre-barcoded stock is to GENERATE a first barcode and print
            // it — excluding exactly the products that need one meant the
            // page showed "No products" for any such shop. Every product
            // is listed now; generateProductBarcode()/generateVariantBarcode()
            // below handle assigning one on demand, same as the variant side
            // already did.
            'products' => Product::orderBy('name')
                ->with([
                    'variants' => fn ($q) => $q->orderBy('size')->orderBy('color'),
                    // docs/ARTICLE_SERIES_PLAN.md §9 — lets this page offer
                    // "one label per color" (summed stock across every size)
                    // as an alternative to picking each size variant one by
                    // one, for a shop that hasn't turned barcode_include_size on
                    'colorBarcodes',
                ])
                ->get(['id', 'name', 'name_en', 'emoji', 'barcode', 'price', 'discount_price'])
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'name_en' => $p->name_en, 'emoji' => $p->emoji,
                    'barcode' => $p->barcode, 'price' => $p->price, 'discount_price' => $p->discount_price,
                    // docs/ARTICLE_SERIES_PLAN.md §10 — scan_code is what the
                    // printed label's bars actually ENCODE (short, scannable
                    // by a phone camera); barcode stays the full descriptive
                    // value still printed as readable text below the bars.
                    'variants' => $p->variants->map(fn ($v) => [
                        'id' => $v->id, 'size' => $v->size, 'color' => $v->color,
                        'barcode' => $v->barcode, 'scan_code' => 'ZV'.$v->id,
                        'price' => $v->price, 'stock' => $v->stock,
                    ]),
                    'color_barcodes' => $includeSize ? [] : $p->colorBarcodes->map(fn ($cb) => [
                        'id' => $cb->id, 'color' => $cb->color, 'barcode' => $cb->barcode,
                        'scan_code' => 'ZC'.$cb->id,
                        'stock' => $p->variants->where('color', $cb->color)->sum('stock'),
                    ]),
                ]),
        ]);
    }

    /**
     * Same job as generateVariantBarcode() below, for a plain product with
     * no variants of its own. 'ZP' . $product->id is unique the same way
     * 'ZV' . $variant->id already is — a single global auto-increment
     * column, never reused across shops or after a delete.
     */
    public function generateProductBarcode(Product $product)
    {
        if (! $product->barcode) {
            $product->update(['barcode' => 'ZP'.$product->id]);
        }

        return back();
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
