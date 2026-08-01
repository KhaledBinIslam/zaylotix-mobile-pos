<?php

namespace App\Notifications;

use App\Models\ProductBatch;
use Illuminate\Notifications\Notification;

/**
 * Sent to a shop's owner when a tracked batch crosses one of the
 * 90/60/30/0-day expiry tiers — the daily zaylotix:expiry-alerts command
 * sends exactly one of these per batch per tier crossed (not a repeat every
 * day the batch happens to still be within 30 days), so an owner sees a
 * batch's urgency escalate three times over its life instead of the same
 * notification piling up daily for a month.
 */
class ExpiryAlert extends Notification
{
    public function __construct(private readonly ProductBatch $batch, private readonly int $tier)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $product = $this->batch->product;
        $daysLeft = now()->startOfDay()->diffInDays($this->batch->expiry_date->startOfDay(), false);
        $batchLabel = $this->batch->batch_no ? " (ব্যাচ {$this->batch->batch_no})" : '';

        $message = $daysLeft <= 0
            ? "'{$product?->name}'{$batchLabel}-এর মেয়াদ শেষ হয়ে গেছে — {$this->batch->qty} ইউনিট। এখনই সরিয়ে ফেলুন, বিক্রি ব্লক করা আছে।"
            : "'{$product?->name}'{$batchLabel}-এর মেয়াদ আর {$daysLeft} দিনে শেষ হবে — {$this->batch->qty} ইউনিট বাকি।";

        return [
            'kind' => 'expiry_alert',
            'title' => $this->tier === 0 ? '🚫 মেয়াদ শেষ হয়ে গেছে' : "⏳ মেয়াদ শেষ হতে {$this->tier} দিন বাকি",
            'message' => $message,
            'product_batch_id' => $this->batch->id,
            'tier' => $this->tier,
            'expiry_date' => $this->batch->expiry_date->toDateString(),
        ];
    }
}
