<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'customer_id', 'sale_id', 'installment_plan_id', 'user_id', 'amount', 'due_before', 'due_after', 'method', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_before' => 'decimal:2',
            'due_after' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function installmentPlan()
    {
        return $this->belongsTo(InstallmentPlan::class);
    }

    /** Which invoice this payment was collected against — nullable, only ever set going forward (see 2026_10_18_000004_add_sale_id_to_payments). */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
