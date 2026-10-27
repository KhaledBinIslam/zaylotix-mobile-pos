<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — append-only history of every value
 * ProductColorBarcode::barcode has ever held for a (product, color), so a
 * sticker printed before a color-code rename / template edit / a
 * products:regenerate-barcodes run stays scannable forever. Never updated
 * in place — see App\Support\ArticleBarcode's regenerate helper, the only
 * place a row here is ever written.
 */
class ProductColorBarcodeAlias extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['shop_id', 'product_id', 'color', 'barcode', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
