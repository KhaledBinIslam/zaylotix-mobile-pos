<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'tag', 'starts_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
        ];
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }
}
