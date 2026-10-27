<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — one row per (product, color), the
 * "tier 2" scannable sticker barcode shared by every size variant of that
 * color. See App\Support\ArticleBarcode::buildStickerBarcode() for how the
 * value itself is computed, and App\Models\ProductColorBarcodeAlias for
 * what happens to the OLD value when this one changes.
 */
class ProductColorBarcode extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'color', 'barcode'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
