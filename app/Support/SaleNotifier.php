<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\Shop;
use App\Notifications\SaleMade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the business owner a sale just happened — bell and/or email —
 * if, and only if, the business asked for it (shops.notify_owner_on_sale
 * / sale_notification_email, read off the SETTINGS shop so both Baby
 * Park branches answer to one switch).
 *
 * Called from the real checkout endpoints AFTER the sale's transaction
 * has committed, never from inside performCheckout() itself:
 * BillingPreview and GatewayPricePreview run performCheckout and roll it
 * back on purpose, and a preview must never email anyone. The smoke-test
 * command's throwaway checkout stays silent the same way.
 *
 * Swallows everything. A notification is the least important thing that
 * happens at checkout, and the one thing it is never allowed to do is
 * take a sale down with it.
 */
class SaleNotifier
{
    public static function afterCheckout(Sale $sale): void
    {
        try {
            $branch = Shop::find($sale->shop_id);
            if (! $branch) {
                return;
            }

            $settings = $branch->articleSettingsShop();
            $wantsBell = (bool) $settings->notify_owner_on_sale;
            $email = trim((string) $settings->sale_notification_email) ?: null;
            if (! $wantsBell && ! $email) {
                return;
            }

            $notification = new SaleMade(
                saleId: $sale->id,
                branchName: $branch->name,
                invoiceNo: (string) $sale->invoice_no,
                total: number_format((float) $sale->total, 2, '.', ''),
                itemCount: $sale->items()->count(),
                paymentMethod: (string) ($sale->payment_mode ?: 'cash'),
                soldAt: now()->format('d/m/Y h:i A'),
                soldBy: $sale->user_id ? \App\Models\User::find($sale->user_id)?->name : null,
            );

            // the owner of the MAIN shop — a branch sale is still their sale
            if ($wantsBell && ($owner = $settings->owner()->first())) {
                $owner->notify($notification);
            }

            if ($email) {
                Notification::route('mail', $email)->notify($notification);
            }
        } catch (\Throwable $e) {
            Log::error('Sale notification failed (sale committed fine)', [
                'sale_id' => $sale->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
