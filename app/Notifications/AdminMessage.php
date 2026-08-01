<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A free-text message the platform admin sends on demand — announcements, maintenance notices, anything that doesn't fit the automatic payment notifications. */
class AdminMessage extends Notification
{
    public function __construct(private readonly string $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'admin_message',
            'title' => '📢 এডমিনের বার্তা',
            'message' => $this->message,
        ];
    }
}
