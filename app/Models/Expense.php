<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'expense_category_id', 'memo', 'amount', 'amount_usd', 'exchange_rate', 'campaign_tag', 'method', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_usd' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'date' => 'date:Y-m-d',
        ];
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35 design note. */
    public function channelSplits()
    {
        return $this->hasMany(ExpenseChannelSplit::class);
    }
}
