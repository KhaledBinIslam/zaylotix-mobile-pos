<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Nightly summary (see App\Console\Commands\SendDailyOwnerSummary) —
 * database-only, same as every other notification in this app
 * (LowStockAlert etc.). `whatsapp_url`, when present (the owner has a
 * phone on file), lets the notification dropdown show a "WhatsApp-এ
 * পাঠান" button that opens a wa.me chat with the owner's OWN number,
 * prefilled with the summary text — a real tap-triggered window.open(),
 * never anything automated, zero cost (see docs/WHATSAPP_BULK_PLAN.md's
 * ground rules, same ones apply here).
 */
class DailyOwnerSummary extends Notification
{
    public function __construct(private readonly string $text, private readonly ?string $whatsappUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'daily_owner_summary',
            'title' => '🌙 আজকের সারাংশ',
            'message' => $this->text,
            'whatsapp_url' => $this->whatsappUrl,
        ];
    }
}
