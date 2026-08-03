<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Notifications\Notification;

/**
 * Sent to a shop's owner when a product's stock — or one specific color/size
 * variant's own stock — drops to/below its configured reorder point. The
 * daily zaylotix:low-stock-alerts command decides who gets one, once per
 * product (or once per variant) per day.
 */
class LowStockAlert extends Notification
{
    public function __construct(private readonly Product $product, private readonly ?ProductVariant $variant = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        if ($this->variant) {
            return [
                'kind' => 'low_stock_alert',
                'title' => '📉 স্টক কমে গেছে',
                'message' => "'{$this->product->name}' ({$this->variant->label()})-এর স্টক কমে {$this->variant->stock}-এ নেমেছে (রিঅর্ডার পয়েন্ট {$this->variant->reorder_point}) — নতুন করে কিনুন।",
                'product_id' => $this->product->id,
                'product_variant_id' => $this->variant->id,
                'stock' => $this->variant->stock,
            ];
        }

        return [
            'kind' => 'low_stock_alert',
            'title' => '📉 স্টক কমে গেছে',
            'message' => "'{$this->product->name}'-এর স্টক কমে {$this->product->stock}-এ নেমেছে (রিঅর্ডার পয়েন্ট {$this->product->reorder_point}) — নতুন করে কিনুন।",
            'product_id' => $this->product->id,
            'stock' => $this->product->stock,
        ];
    }
}
