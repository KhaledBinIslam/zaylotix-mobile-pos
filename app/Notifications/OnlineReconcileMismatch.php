<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Sent to a shop's owner once a day when `online:reconcile` finds a real
 * mismatch for their shop — see docs/FIX_PLAN.md's Phase 37 design note.
 * One notification per day summarizing counts, never one per mismatched
 * row (same "don't spam" reasoning as LowStockAlert's own dedup).
 */
class OnlineReconcileMismatch extends Notification
{
    public function __construct(private readonly int $revenueMismatchCount, private readonly int $settlementMismatchCount)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $parts = [];
        if ($this->revenueMismatchCount > 0) {
            $parts[] = "{$this->revenueMismatchCount}টা অনলাইন অর্ডারের রিপোর্টিং স্ট্যাটাস ঠিক নেই";
        }
        if ($this->settlementMismatchCount > 0) {
            $parts[] = "{$this->settlementMismatchCount}টা কুরিয়ার সেটেলমেন্ট মিলছে না";
        }

        return [
            'kind' => 'online_reconcile_mismatch',
            'title' => '⚠️ অনলাইন অর্ডার রিকনসিলিয়েশনে সমস্যা',
            'message' => implode(', ', $parts).' — `php artisan online:reconcile` চালিয়ে বিস্তারিত দেখুন।',
            'revenue_mismatch_count' => $this->revenueMismatchCount,
            'settlement_mismatch_count' => $this->settlementMismatchCount,
        ];
    }
}
