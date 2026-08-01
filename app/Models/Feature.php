<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['key', 'label_bn', 'label_en', 'category', 'description', 'is_active', 'monthly_price'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'monthly_price' => 'decimal:2'];
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'shop_features');
    }
}
