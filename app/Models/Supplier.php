<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['shop_id', 'name', 'phone', 'address', 'payable', 'short_code'];

    protected function casts(): array
    {
        return [
            'payable' => 'decimal:2',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function returns()
    {
        return $this->hasMany(SupplierReturn::class);
    }

    /**
     * Every supplier for a picker dropdown, each carrying how many
     * products actually use it — Khaled live: "Sujon" and "Sujon 3rd
     * party" (two real, separate suppliers) were indistinguishable in a
     * plain name-only dropdown, so the wrong one got picked without
     * anyone noticing. Counts distinct products with at least one Part 1
     * LINE from that supplier (a source_lines shop — one query covers
     * both a plain product's own line and every variant's, since
     * product_id is set on both); falls back to the plain
     * product.supplier_id count for a shop without the feature, same
     * split every other supplier-aware query in this app already uses.
     */
    public static function pickerList(): \Illuminate\Support\Collection
    {
        $suppliers = static::orderBy('name')->get(['id', 'name']);
        if ($suppliers->isEmpty()) {
            return $suppliers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'count' => 0]);
        }

        $counts = \App\Support\Tenancy::shop()?->hasFeature('source_lines')
            ? ProductSource::whereNotNull('supplier_id')
                ->selectRaw('supplier_id, COUNT(DISTINCT product_id) as c')
                ->groupBy('supplier_id')->pluck('c', 'supplier_id')
            : Product::whereNotNull('supplier_id')
                ->selectRaw('supplier_id, COUNT(*) as c')
                ->groupBy('supplier_id')->pluck('c', 'supplier_id');

        return $suppliers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'count' => (int) ($counts[$s->id] ?? 0)]);
    }
}
