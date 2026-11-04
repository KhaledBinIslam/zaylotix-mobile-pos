<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One sale, reported to the business owner the moment it lands —
 * whichever branch rang it up. Khaled: "sells holei j branch e hoq baby
 * park er 2 ta branch e owner [ke] sathe sathe notification and mail".
 *
 * Queued: checkout is the busiest moment the app has, and neither a bell
 * row nor an SMTP round-trip belongs inside it. The production cron's
 * in-process queue:work pass delivers both within the minute.
 *
 * Carries scalars, never the Sale model — by the time the worker runs
 * the sale may have been voided, and a serialized model would refuse to
 * restore where plain values still describe what happened.
 *
 * The same instance serves both audiences: sent TO the owner it writes
 * the database (bell) row; sent to an on-demand mail route
 * (SaleNotifier) it writes the email. via() tells them apart by what it
 * was given.
 */
class SaleMade extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $saleId,
        private readonly string $branchName,
        private readonly string $invoiceNo,
        private readonly string $total,
        private readonly int $itemCount,
        private readonly string $paymentMethod,
        private readonly string $soldAt,
        private readonly ?string $soldBy,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'sale_made',
            'title' => '🛒 নতুন বিক্রি — '.$this->branchName,
            'message' => "{$this->invoiceNo}: ৳{$this->total} ({$this->itemCount}টি আইটেম, {$this->paymentMethod})"
                .($this->soldBy ? " — {$this->soldBy}" : ''),
            'sale_id' => $this->saleId,
            'total' => $this->total,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("🛒 নতুন বিক্রি — {$this->branchName} — ৳{$this->total}")
            ->greeting("{$this->branchName}-এ একটা বিক্রি হলো")
            ->line("ইনভয়েস: {$this->invoiceNo}")
            ->line("মোট: ৳{$this->total} — {$this->itemCount}টি আইটেম ({$this->paymentMethod})")
            ->line('সময়: '.$this->soldAt.($this->soldBy ? " — বিক্রি করেছেন: {$this->soldBy}" : ''))
            ->salutation('Zaylotix POS');
    }
}
