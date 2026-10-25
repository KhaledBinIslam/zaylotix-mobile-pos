<?php

namespace App\Console\Commands;

use App\Models\CourierSettlement;
use App\Models\OnlineOrder;
use App\Models\Shop;
use App\Notifications\OnlineReconcileMismatch;
use Illuminate\Console\Command;

/**
 * "Online Business" module, part 7 — see docs/FIX_PLAN.md's Phase 37
 * design note. Read-only, two checks this CAN verify reliably (same
 * "only flag what's actually detectable" discipline as DueReconcile):
 *
 *  1. Revenue-recognition integrity — an online order's own Sale must
 *     have counted_in_reports = true if and only if the order has ever
 *     been delivered (delivered_at set). This is Part 2's entire
 *     design contract (see Sale::booted()'s own docblock); a mismatch
 *     here means either revenue is missing from every report, or
 *     counted before it should be.
 *  2. COD settlement integrity — a CourierSettlement's own
 *     expected_amount must equal the sum of cod_amount across the
 *     orders actually linked to it, and cod_settled_at/
 *     courier_settlement_id must always be both-set or both-null on
 *     the same order.
 *
 * Without --notify this is a plain console report (manual use, same as
 * `due:reconcile`/`variants:stock-mismatch`). With --notify (what the
 * daily schedule uses) it also sends each affected shop's owner one
 * `OnlineReconcileMismatch` notification, at most once per day.
 */
class OnlineOrderReconcile extends Command
{
    protected $signature = 'online:reconcile {--shop=} {--notify}';

    protected $description = 'Read-only: flags online orders whose revenue-recognition status or COD settlement data doesn\'t add up.';

    public function handle(): int
    {
        $shopId = $this->option('shop');

        $orders = OnlineOrder::withoutGlobalScopes()
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->with('sale')
            ->get();

        $revenueMismatches = $orders->filter(function (OnlineOrder $o) {
            if (! $o->sale) {
                return false; // nothing to compare against; not this command's job to flag an orphaned order
            }

            $shouldBeCounted = $o->delivered_at !== null;

            return (bool) $o->sale->counted_in_reports !== $shouldBeCounted;
        });

        $settlementStatusMismatches = $orders->filter(fn (OnlineOrder $o) => ($o->cod_settled_at !== null) !== ($o->courier_settlement_id !== null));

        $settlements = CourierSettlement::withoutGlobalScopes()
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->get();
        $actualTotals = OnlineOrder::withoutGlobalScopes()->whereNotNull('courier_settlement_id')
            ->selectRaw('courier_settlement_id, sum(cod_amount) as total')
            ->groupBy('courier_settlement_id')
            ->pluck('total', 'courier_settlement_id');
        $settlementAmountMismatches = $settlements->filter(fn (CourierSettlement $s) => abs((float) ($actualTotals->get($s->id) ?? 0) - (float) $s->expected_amount) > 0.01);

        $settlementMismatchCount = $settlementStatusMismatches->count() + $settlementAmountMismatches->count();

        if ($revenueMismatches->isEmpty() && $settlementMismatchCount === 0) {
            $this->info('কোনো সমস্যা পাওয়া যায়নি — সব অনলাইন অর্ডারের রিপোর্টিং স্ট্যাটাস ও কুরিয়ার সেটেলমেন্ট ঠিক আছে।');

            return self::SUCCESS;
        }

        if ($revenueMismatches->isNotEmpty()) {
            $this->error($revenueMismatches->count().'টা অর্ডারের counted_in_reports status ডেলিভারি status-এর সাথে মিলছে না:');
            $this->table(
                ['Order', 'Shop', 'Status', 'Delivered at', 'counted_in_reports'],
                $revenueMismatches->map(fn (OnlineOrder $o) => [$o->order_number, $o->shop_id, $o->status, $o->delivered_at?->toDateTimeString() ?? '—', $o->sale->counted_in_reports ? 'true' : 'false'])
            );
        }

        if ($settlementStatusMismatches->isNotEmpty()) {
            $this->error($settlementStatusMismatches->count().'টা অর্ডারের cod_settled_at/courier_settlement_id একসাথে set/unset নয়:');
            $this->table(
                ['Order', 'Shop', 'cod_settled_at', 'courier_settlement_id'],
                $settlementStatusMismatches->map(fn (OnlineOrder $o) => [$o->order_number, $o->shop_id, $o->cod_settled_at?->toDateTimeString() ?? '—', $o->courier_settlement_id ?? '—'])
            );
        }

        if ($settlementAmountMismatches->isNotEmpty()) {
            $this->error($settlementAmountMismatches->count().'টা সেটেলমেন্টের expected_amount তার সাথে লিংকড অর্ডারগুলোর cod_amount যোগফলের সাথে মিলছে না:');
            $this->table(
                ['Settlement ID', 'Shop', 'expected_amount', 'actual order total'],
                $settlementAmountMismatches->map(fn (CourierSettlement $s) => [$s->id, $s->shop_id, $s->expected_amount, round((float) ($actualTotals->get($s->id) ?? 0), 2)])
            );
        }

        $this->newLine();
        $this->info('এটা read-only — কিছু বদলানো হয়নি।');

        if ($this->option('notify')) {
            $this->notifyOwners($revenueMismatches, $settlementStatusMismatches, $settlementAmountMismatches);
        }

        return self::FAILURE;
    }

    private function notifyOwners($revenueMismatches, $settlementStatusMismatches, $settlementAmountMismatches): void
    {
        $shopIds = $revenueMismatches->pluck('shop_id')
            ->merge($settlementStatusMismatches->pluck('shop_id'))
            ->merge($settlementAmountMismatches->pluck('shop_id'))
            ->unique();

        $shops = Shop::withoutGlobalScopes()->with('owner')->whereIn('id', $shopIds)->get();

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            $alreadySentToday = $owner->notifications()
                ->where('type', OnlineReconcileMismatch::class)
                ->whereDate('created_at', now()->toDateString())
                ->exists();
            if ($alreadySentToday) {
                continue;
            }

            $revenueCount = $revenueMismatches->where('shop_id', $shop->id)->count();
            $settlementCount = $settlementStatusMismatches->where('shop_id', $shop->id)->count()
                + $settlementAmountMismatches->where('shop_id', $shop->id)->count();

            $owner->notify(new OnlineReconcileMismatch($revenueCount, $settlementCount));
        }
    }
}
