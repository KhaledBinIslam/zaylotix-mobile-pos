<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'user_id', 'type', 'amount', 'from_label', 'to_label', 'note', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
