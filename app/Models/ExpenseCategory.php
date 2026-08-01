<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'name_en', 'emoji'];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
