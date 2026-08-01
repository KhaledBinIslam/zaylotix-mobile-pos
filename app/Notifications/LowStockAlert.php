<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Notifications\Notification;

/** Sent to a shop's owner when a product's stock drops to/below its configured reorder point — the daily zaylotix:low-stock-alerts command decides who gets one, once per product per day. */
class LowStockAlert extends Notification
{
    public function __construct(private readonly Product $product)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'low_stock_alert',
            'title' => '📉 স্টক কমে গেছে',
            'message' => "'{$this->product->name}'-এর স্টক কমে {$this->product->stock}-এ নেমেছে (রিঅর্ডার পয়েন্ট {$this->product->reorder_point}) — নতুন করে কিনুন।",
            'product_id' => $this->product->id,
            'stock' => $this->product->stock,
        ];
    }
}
