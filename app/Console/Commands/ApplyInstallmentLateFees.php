<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Charges a late fee, exactly once, on every installment that just became
 * overdue (due_date before today, still pending, never charged before —
 * late_fee_charged_at is the one-shot guard). Real money owed increases:
 * customers.due goes up by the fee, same as any other legitimate charge,
 * not just a cosmetic number on the schedule — a shop that collects the
 * fee sees it the same way it'd see any other due. Loops shop by shop with
 * Tenancy::set() (same shape as SendExpiryAlerts) since every model
 * touched here is tenant-scoped and this runs outside any HTTP request.
 */
class ApplyInstallmentLateFees extends Command
{
    protected $signature = 'zaylotix:installment-late-fees';

    protected $description = "Apply a plan's configured late fee to every installment that's newly overdue.";

    public function handle(): int
    {
        $shops = Shop::where('status', 'active')
            ->whereHas('features', fn ($q) => $q->where('key', 'installment_sales'))
            ->get(['id']);

        $charged = 0;

        foreach ($shops as $shop) {
            Tenancy::set($shop->id);

            $items = InstallmentScheduleItem::with('plan.customer')
                ->where('status', 'pending')
                ->whereNull('late_fee_charged_at')
                ->whereDate('due_date', '<', now()->toDateString())
                ->get();

            foreach ($items as $item) {
                $plan = $item->plan;
                if (! $plan || $plan->status !== 'active' || ! $plan->late_fee_type || ! $plan->late_fee_amount) {
                    continue;
                }

                $fee = $plan->late_fee_type === 'percent'
                    ? round((float) $item->amount * (float) $plan->late_fee_amount / 100, 2)
                    : (float) $plan->late_fee_amount;

                if ($fee <= 0) {
                    continue;
                }

                DB::transaction(function () use ($item, $plan, $fee) {
                    $item->update(['late_fee_applied' => (float) $item->late_fee_applied + $fee, 'late_fee_charged_at' => now()]);

                    $customer = Customer::whereKey($plan->customer_id)->lockForUpdate()->first();
                    $customer?->increment('due', $fee);

                    Activity::log('installment.lateFee', "{$plan->customer->name}-এর কিস্তিতে বিলম্ব ফি {$fee} টাকা যুক্ত হয়েছে।", $item, ['fee' => $fee]);
                });

                $charged++;
            }

            Tenancy::clear();
        }

        $this->info("Applied late fee to {$charged} installment(s).");

        return self::SUCCESS;
    }
}
