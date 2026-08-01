<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'batch_no', 'expiry_date', 'qty', 'cost'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('qty', '>', 0);
    }

    /** Soonest-expiring first (FEFO); batches with no expiry date sort last — nothing's rushing them out. */
    public function scopeFefoOrder($query)
    {
        return $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id');
    }

    /** Excludes a batch whose expiry_date has already passed — see BatchStock for how this actually blocks a sale, not just bookkeeping. */
    public function scopeNotExpired($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()));
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->startOfDay()->lt(now()->startOfDay());
    }
}
