<?php

namespace App\Notifications;

use App\Models\ProductSerial;
use Illuminate\Notifications\Notification;

/**
 * Phase 3 feature 9 — "ধরন-ভিত্তিক সুবিধা" (mobile/electronics vertical, see
 * docs/FIX_PLAN.md's design note). Sent to a shop's owner as a sold unit's
 * IMEI warranty crosses a 30/60/0-day tier — mirrors ExpiryAlert's own
 * tiered/dedup-by-tier structure exactly, just against warranty_expiry
 * instead of a batch's expiry_date.
 */
class WarrantyExpiringSoon extends Notification
{
    public function __construct(private readonly ProductSerial $serial, private readonly int $tier)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $saleItem = $this->serial->saleItem;
        $customer = $saleItem?->sale?->customer;
        $productName = $this->serial->product?->name ?? 'পণ্য';
        $customerNote = $customer ? " ({$customer->name}, {$customer->phone})" : '';

        $message = $this->tier <= 0
            ? "'{$productName}' (IMEI {$this->serial->imei}){$customerNote}-এর ওয়ারেন্টি শেষ হয়ে গেছে/আজই শেষ।"
            : "'{$productName}' (IMEI {$this->serial->imei}){$customerNote}-এর ওয়ারেন্টি আর {$this->tier} দিনের মধ্যে শেষ হবে।";

        return [
            'kind' => 'warranty_expiring_soon',
            'title' => '🔧 ওয়ারেন্টি মেয়াদ শেষের পথে',
            'message' => $message,
            'product_serial_id' => $this->serial->id,
            'tier' => $this->tier,
        ];
    }
}
