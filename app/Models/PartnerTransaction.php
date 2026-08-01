<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PartnerTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'partner_id', 'type', 'amount', 'method', 'note', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
