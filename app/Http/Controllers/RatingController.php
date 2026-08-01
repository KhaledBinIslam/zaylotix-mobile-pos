<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleRating;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public, no-login rating page reached by scanning the QR code printed on a
 * customer's paper receipt — Sale/SaleRating are both tenant-scoped models,
 * but there is no authenticated shop context here, so every query below
 * explicitly bypasses that global scope instead of relying on Tenancy::id().
 */
class RatingController extends Controller
{
    public function show(int $sale)
    {
        $saleModel = Sale::withoutGlobalScopes()->with('shop:id,name,logo_path')->findOrFail($sale);
        $existing = SaleRating::withoutGlobalScopes()->where('sale_id', $sale)->first();

        return Inertia::render('Public/Rate', [
            'sale' => [
                'id' => $saleModel->id,
                'invoice_no' => $saleModel->invoice_no,
                'total' => $saleModel->total,
                'shop_name' => $saleModel->shop?->name,
                'shop_logo_url' => $saleModel->shop?->logo_url,
            ],
            'existing' => $existing ? ['stars' => $existing->stars, 'comment' => $existing->comment] : null,
        ]);
    }

    public function store(Request $request, int $sale)
    {
        $saleModel = Sale::withoutGlobalScopes()->findOrFail($sale);

        $data = $request->validate([
            'stars' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        SaleRating::withoutGlobalScopes()->updateOrCreate(
            ['sale_id' => $saleModel->id],
            ['shop_id' => $saleModel->shop_id, 'stars' => $data['stars'], 'comment' => $data['comment'] ?? null],
        );

        return back()->with('success', 'ধন্যবাদ! আপনার মতামত পৌঁছে গেছে।');
    }
}
