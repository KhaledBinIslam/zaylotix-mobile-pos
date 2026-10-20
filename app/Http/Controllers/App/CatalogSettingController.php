<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CatalogSettingController extends Controller
{
    /**
     * Lists every product so the owner can pick which ones show on the
     * public catalog (see docs/FIX_PLAN.md's design note) — lazily
     * assigns the shop's catalog_slug the first time this page is opened,
     * so a shop that never turns the feature on never gets one.
     */
    public function index()
    {
        $shop = Tenancy::shop();

        if (! $shop->catalog_slug) {
            $shop->update(['catalog_slug' => $this->generateUniqueSlug($shop->name)]);
        }

        $products = $shop->products()
            ->orderBy('name')
            ->get(['id', 'name', 'emoji', 'price', 'show_in_catalog']);

        return Inertia::render('App/Catalog/Index', [
            'catalogUrl' => $shop->catalogUrl(),
            'products' => $products,
        ]);
    }

    private function generateUniqueSlug(string $shopName): string
    {
        $base = Str::slug($shopName) ?: 'shop';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (\App\Models\Shop::withoutGlobalScopes()->where('catalog_slug', $slug)->exists());

        return $slug;
    }
}
