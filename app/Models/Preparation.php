<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Preparation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'product_name', 'qty', 'created_by'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function items()
    {
        return $this->hasMany(PreparationItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
