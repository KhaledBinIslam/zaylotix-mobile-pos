<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35 design note. */
class ExpenseChannelSplit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'expense_id', 'channel_id', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function channel()
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }
}
