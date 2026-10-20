<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Inertia\Inertia;

/**
 * Public, no-login digital catalog page (see docs/FIX_PLAN.md's "ডিজিটাল
 * ক্যাটালগ লিঙ্ক" design note) — same public-page pattern as the existing
 * rate/{sale} QR flow (RatingController): Shop/Product are tenant-scoped
 * models, but there is no authenticated shop context here, so every query
 * below explicitly bypasses that global scope.
 *
 * Deliberately never exposes cost_price, stock-cost, or any other
 * money-in figure — only what a window-shopper would see in person:
 * name, photo, retail price, and whether it's in stock.
 */
class CatalogController extends Controller
{
    public function show(string $slug)
    {
        $shop = Shop::withoutGlobalScopes()->where('catalog_slug', $slug)->first();

        if (! $shop || ! $shop->hasFeature('digital_catalog') || ! $shop->isActive()) {
            abort(404);
        }

        $products = $shop->products()
            ->withoutGlobalScopes()
            ->where('show_in_catalog', true)
            ->orderBy('name')
            ->get(['id', 'name', 'name_en', 'emoji', 'photo_path', 'price', 'discount_price', 'stock', 'stock_mode'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'name_en' => $p->name_en,
                'emoji' => $p->emoji,
                'photo_url' => $p->photo_url,
                'price' => (float) ($p->discount_price ?? $p->price),
                'original_price' => $p->discount_price ? (float) $p->price : null,
                'in_stock' => ! $p->isOut(),
            ]);

        $waNumber = '88'.preg_replace('/\D/', '', (string) $shop->phone);

        return Inertia::render('Public/Catalog', [
            'shop' => [
                'name' => $shop->name,
                'logo_url' => $shop->logo_url,
                'area' => $shop->area,
                'whatsapp_number' => $waNumber,
            ],
            'products' => $products,
        ]);
    }
}
