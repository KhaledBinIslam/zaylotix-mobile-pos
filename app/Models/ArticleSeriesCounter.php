<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately NOT tenant-scoped (no BelongsToTenant) — always keyed by a
 * MAIN shop's id regardless of which of its branches is actually creating
 * an article. See docs/ARTICLE_SERIES_PLAN.md §2 and
 * App\Support\ArticleSerial, which is the only place this model should be
 * touched from.
 */
class ArticleSeriesCounter extends Model
{
    protected $fillable = ['main_shop_id', 'category_code', 'next_serial'];

    public function mainShop()
    {
        return $this->belongsTo(Shop::class, 'main_shop_id');
    }
}
