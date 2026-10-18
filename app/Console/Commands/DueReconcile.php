<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Read-only sanity check for customers.due — the single running total this
 * app treats as the source of truth for what a customer owes (see
 * docs/ARCHITECTURE_NOTES.md section 5: there is no per-invoice ledger
 * today, so this cannot reconstruct an independent "expected due" from
 * scratch — that would need the per-invoice tracking flagged as its own
 * design decision in docs/FIX_PLAN.md Phase 3.2). Flags two classes of
 * problem it CAN detect reliably without that:
 *
 *  1. A negative due — every code path that touches due clamps at 0
 *     (max(0, ...)), so a negative value can only mean something bypassed
 *     that, a direct DB edit, or a genuine bug.
 *  2. A broken Payment ledger chain — each Payment row snapshots
 *     due_before/due_after at the moment it was recorded (see
 *     PaymentController::store()); consecutive payments for the same
 *     customer should chain due_after -> due_before exactly. A break means
 *     two payments raced each other, or something else modified due
 *     between them outside the normal flow.
 *
 * Always read-only — there is no --confirm/auto-fix mode, since nothing
 * here can safely know which value is actually correct; a flagged customer
 * needs a human to look at their actual sale/return/payment history.
 */
class DueReconcile extends Command
{
    protected $signature = 'due:reconcile {--shop=}';

    protected $description = 'Read-only: flags customers with a negative due or a broken payment-ledger chain.';

    public function handle(): int
    {
        $customers = Customer::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $shopId) => $q->where('shop_id', $shopId))
            ->get();

        $negative = [];
        $brokenChain = [];

        foreach ($customers as $customer) {
            if ((float) $customer->due < 0) {
                $negative[] = $customer;
            }

            $payments = Payment::withoutGlobalScopes()->where('customer_id', $customer->id)->orderBy('id')->get();
            $expectedBefore = null;
            foreach ($payments as $payment) {
                if ($expectedBefore !== null && abs((float) $payment->due_before - $expectedBefore) > 0.01) {
                    $brokenChain[] = $customer;
                    break;
                }
                $expectedBefore = (float) $payment->due_after;
            }
        }

        if (empty($negative) && empty($brokenChain)) {
            $this->info('কোনো সমস্যা পাওয়া যায়নি — সব কাস্টমারের due ঠিক আছে (এই দুইটা check অনুযায়ী)।');

            return self::SUCCESS;
        }

        if (! empty($negative)) {
            $this->error(count($negative).'টা কাস্টমারের due negative:');
            $this->table(['ID', 'Shop', 'Name', 'Due'], collect($negative)->map(fn ($c) => [$c->id, $c->shop_id, $c->name, $c->due]));
        }

        if (! empty($brokenChain)) {
            $this->error(count($brokenChain).'টা কাস্টমারের payment ledger chain ভাঙা (একাধিক payment একসাথে race করেছে, বা due অন্য কোথাও থেকে বদলেছে):');
            $this->table(['ID', 'Shop', 'Name', 'Current due'], collect($brokenChain)->map(fn ($c) => [$c->id, $c->shop_id, $c->name, $c->due]));
        }

        $this->newLine();
        $this->info('এটা read-only — কিছু বদলানো হয়নি। এই কাস্টমারদের sale/return/payment history ম্যানুয়ালি চেক করুন।');

        return self::FAILURE;
    }
}
