<?php

namespace App\Notifications;

use App\Models\Shop;
use Illuminate\Notifications\Notification;

/** Sent to a shop's owner a few days before their subscription/trial expiry — the daily zaylotix:payment-reminders command decides who gets one and never sends the same shop two reminders on the same day. */
class PaymentDueReminder extends Notification
{
    public function __construct(private readonly Shop $shop, private readonly int $daysLeft)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $kind = $this->shop->plan === 'trial' ? 'ফ্রি ট্রায়াল' : 'সাবস্ক্রিপশন';
        $message = $this->daysLeft <= 0
            ? "আপনার {$kind} আজই শেষ হচ্ছে — এখনই বিল পরিশোধ করুন, না হলে দোকান বন্ধ হয়ে যাবে।"
            : "আপনার {$kind} শেষ হতে আর {$this->daysLeft} দিন বাকি — মেয়াদ শেষ হওয়ার আগেই বিল পরিশোধ করুন।";

        return [
            'kind' => 'payment_due_reminder',
            'title' => '⏰ পেমেন্ট রিমাইন্ডার',
            'message' => $message,
            'days_left' => $this->daysLeft,
            'subscription_expiry' => $this->shop->subscription_expiry?->toDateString(),
        ];
    }
}
