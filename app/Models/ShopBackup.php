<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ShopBackup extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'requested_by', 'format', 'status', 'file_path', 'failed_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
