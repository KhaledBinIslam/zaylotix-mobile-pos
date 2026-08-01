<?php

namespace App\Notifications;

use App\Models\SubscriptionPayment;
use Illuminate\Notifications\Notification;

/** Sent to a shop's owner the moment admin records their subscription payment — confirms the money was received and shows the new due date. */
class PaymentReceived extends Notification
{
    public function __construct(private readonly SubscriptionPayment $payment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $amount = number_format((float) $this->payment->amount);
        $nextDue = $this->payment->next_due?->toDateString();

        return [
            'kind' => 'payment_received',
            'title' => '✅ পেমেন্ট গ্রহণ করা হয়েছে',
            'message' => "৳{$amount} টাকা পাওয়া গেছে।".($nextDue ? " পরবর্তী মেয়াদ: {$nextDue}" : ''),
            'amount' => (float) $this->payment->amount,
            'month' => $this->payment->month,
            'next_due' => $nextDue,
        ];
    }
}
