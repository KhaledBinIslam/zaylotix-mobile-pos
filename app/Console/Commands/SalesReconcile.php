<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Console\Command;

/**
 * Phase 39 (docs/FIX_PLAN.md) — read-only sanity check for voided sales,
 * sibling of due:reconcile. There is no pre-sale snapshot stored anywhere,
 * so this cannot re-derive "what the numbers should be" from scratch
 * (same limitation due:reconcile documents); it flags the inconsistency
 * classes that CAN be detected reliably:
 *
 *  1. A voided sale that ALSO has return/exchange rows — the void flow
 *     blocks this combination (SalesController::destroy()), so its
 *     existence means stock/cash was credited twice for the same items.
 *  2. Negative balances on a voided sale's customer — every reversal path
 *     clamps at 0, so a negative can only mean something bypassed that.
 *  3. More than one 'sale.void' activity entry for the same sale — the
 *     reversal must only ever have run once per sale.
 *
 * Always read-only; --dry-run is accepted for consistency with the other
 * reconcile tooling but changes nothing, since nothing is ever written.
 */
class SalesReconcile extends Command
{
    protected $signature = 'sales:reconcile {--shop=} {--dry-run}';

    protected $description = 'Read-only: flags voided sales whose reversal looks inconsistent (double-credit, negative balances, double-void).';

    public function handle(): int
    {
        $voided = Sale::withoutGlobalScopes()->whereNotNull('voided_at')
            ->when($this->option('shop'), fn ($q, $shopId) => $q->where('shop_id', $shopId))
            ->get();

        $this->info('মোট voided বিল পাওয়া গেছে: '.$voided->count());

        $withReturns = [];
        $negativeCustomers = [];
        $doubleVoided = [];

        foreach ($voided as $sale) {
            if (SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->exists()) {
                $withReturns[] = $sale;
            }

            if ($sale->customer_id) {
                $customer = Customer::withoutGlobalScopes()->find($sale->customer_id);
                if ($customer && ((float) $customer->due < 0 || (float) $customer->advance_balance < 0
                    || (int) $customer->loyalty_points < 0 || (int) $customer->visits < 0)) {
                    $negativeCustomers[$customer->id] = [$sale, $customer];
                }
            }

            $voidLogs = ActivityLog::withoutGlobalScopes()
                ->where('shop_id', $sale->shop_id)->where('action', 'sale.void')
                ->where('subject_type', Sale::class)->where('subject_id', $sale->id)
                ->count();
            if ($voidLogs > 1) {
                $doubleVoided[] = [$sale, $voidLogs];
            }
        }

        if (empty($withReturns) && empty($negativeCustomers) && empty($doubleVoided)) {
            $this->info('কোনো সমস্যা পাওয়া যায়নি — সব voided বিলের reversal সামঞ্জস্যপূর্ণ (এই তিনটা check অনুযায়ী)।');

            return self::SUCCESS;
        }

        if (! empty($withReturns)) {
            $this->error(count($withReturns).'টা voided বিলে return/exchange-ও আছে (double-credit এর ইঙ্গিত):');
            $this->table(['Sale ID', 'Shop', 'Invoice', 'Total', 'Voided at'],
                collect($withReturns)->map(fn ($s) => [$s->id, $s->shop_id, $s->invoice_no, $s->total, $s->voided_at]));
        }

        if (! empty($negativeCustomers)) {
            $this->error(count($negativeCustomers).'টা voided বিলের কাস্টমারের ব্যালেন্স negative:');
            $this->table(['Sale ID', 'Customer', 'Due', 'Advance', 'Points', 'Visits'],
                collect($negativeCustomers)->map(fn ($pair) => [$pair[0]->id, $pair[1]->name, $pair[1]->due, $pair[1]->advance_balance, $pair[1]->loyalty_points, $pair[1]->visits]));
        }

        if (! empty($doubleVoided)) {
            $this->error(count($doubleVoided).'টা বিলে একাধিক void activity entry (reversal একাধিকবার চলার ইঙ্গিত):');
            $this->table(['Sale ID', 'Shop', 'Invoice', 'Void log count'],
                collect($doubleVoided)->map(fn ($pair) => [$pair[0]->id, $pair[0]->shop_id, $pair[0]->invoice_no, $pair[1]]));
        }

        $this->newLine();
        $this->info('এটা read-only — কিছু বদলানো হয়নি। চিহ্নিত বিলগুলোর sale/return/payment history ম্যানুয়ালি চেক করুন।');

        return self::FAILURE;
    }
}
