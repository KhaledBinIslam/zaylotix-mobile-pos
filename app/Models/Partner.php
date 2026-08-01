<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'phone', 'ownership_percent', 'invested_amount', 'withdrawn_amount', 'joined_date'];

    protected function casts(): array
    {
        return [
            'ownership_percent' => 'decimal:2',
            'invested_amount' => 'decimal:2',
            'withdrawn_amount' => 'decimal:2',
            'joined_date' => 'date',
        ];
    }

    public function transactions()
    {
        return $this->hasMany(PartnerTransaction::class);
    }
}
