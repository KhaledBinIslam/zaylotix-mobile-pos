<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use BelongsToTenant;

    protected $table = 'returns';

    protected $fillable = [
        'shop_id', 'product_id', 'product_batch_id', 'user_id', 'qty', 'refund', 'phone', 'date',
        'sale_id', 'sale_item_id', 'product_variant_id', 'customer_id', 'exchange_sale_id',
        'type', 'condition', 'reason', 'cost', 'applied_to_due', 'refund_cash', 'refund_bank', 'refund_due', 'loyalty_points_deducted',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'refund' => 'decimal:2',
            'cost' => 'decimal:2',
            'refund_cash' => 'decimal:2',
            'refund_bank' => 'decimal:2',
            'refund_due' => 'decimal:2',
            'applied_to_due' => 'boolean',
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * Only the returns that still belong to the books: the return of a bill that was later VOIDED (or is not counted in
     * reports) is left out, exactly as the bill itself is. Voiding a bill that had a return reverses both together
     * (SaleReversal nets the refund), so the pair contributes nothing — but a report that dropped the bill and kept the
     * refund showed a phantom loss (QA Phase 3, D13: sale 200, return 100, void -> profit -40 on a day with no sale).
     * Legacy rows with no sale_id (the old no-receipt return flow) are kept. Cross-branch rows are judged by the bill
     * they point at, wherever it was sold, so no global scope is involved in the lookup.
     */
    public function scopeOnLiveBills($query)
    {
        $table = $query->getModel()->getTable();

        return $query->where(function ($w) use ($table) {
            $w->whereNull("{$table}.sale_id")->orWhereExists(function ($e) use ($table) {
                $e->selectRaw('1')->from('sales')
                    ->whereColumn('sales.id', "{$table}.sale_id")
                    ->whereNull('sales.voided_at')
                    ->where('sales.counted_in_reports', true);
            });
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function exchangeSale()
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }
}
