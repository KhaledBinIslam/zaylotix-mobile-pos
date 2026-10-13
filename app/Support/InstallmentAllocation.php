<?php

namespace App\Support;

use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;

/**
 * Applies a real due-collection (see PaymentController::store()) onto a
 * plan's own schedule — oldest due_date first, same FEFO-style "take what
 * you can from the earliest row, spill into the next" shape as
 * BatchStock::deduct(). customers.due is decremented by the caller exactly
 * as before; this only tracks which specific installment(s) that money
 * actually covers, so the schedule and the overdue report stay accurate
 * without the collector ever having to pick a installment line themselves.
 */
class InstallmentAllocation
{
    public static function apply(InstallmentPlan $plan, float $amount): void
    {
        $remaining = $amount;

        $items = InstallmentScheduleItem::where('installment_plan_id', $plan->id)
            ->where('status', 'pending')
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()->get();

        foreach ($items as $item) {
            if ($remaining <= 0) {
                break;
            }

            $owed = $item->balance();
            if ($owed <= 0) {
                continue;
            }

            $take = min($owed, $remaining);
            $item->increment('amount_paid', $take);
            $remaining -= $take;

            if ($item->fresh()->balance() <= 0.01) {
                $item->update(['status' => 'paid', 'paid_at' => now()]);
            }
        }

        if (InstallmentScheduleItem::where('installment_plan_id', $plan->id)->where('status', '!=', 'paid')->doesntExist()) {
            $plan->update(['status' => 'completed']);
        }
    }
}
