<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'parent_id', 'name', 'name_en', 'emoji', 'code', 'serial_start'];

    protected $casts = ['serial_start' => 'integer'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The ids a category filter should match: the category itself plus its
     * direct children (two levels is the maximum, so no recursion). "Show
     * me shoes" must include every kind of shoe — a parent tab that hid
     * its sub-categories' products would read as missing stock.
     *
     * @return int[]
     */
    public static function idsIncludingChildren(int $categoryId): array
    {
        return array_merge(
            [$categoryId],
            self::where('parent_id', $categoryId)->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
