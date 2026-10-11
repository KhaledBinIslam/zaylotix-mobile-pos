<?php

namespace App\Support;

use App\Models\Ambassador;
use App\Models\AmbassadorCommission;
use App\Models\AmbassadorShopAssignment;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for every ambassador commission row -- the one-time
 * bonus (when a shop is first marked "brought by" an ambassador) and the
 * recurring cut (whenever a subscription payment lands on a shop that has a
 * currently-assigned ambassador). Nothing else should write to
 * ambassador_commissions directly.
 */
class AmbassadorCommissionService
{
    /**
     * Call right after Shop::update(['brought_by_ambassador_id' => ...]).
     * Only ever creates the one-time bonus on a genuine null -> set
     * transition -- correcting an already-set value (fixing a mistake)
     * never silently creates a second bonus row.
     */
    public static function handleBroughtByChange(Shop $shop, ?int $previousAmbassadorId, ?int $newAmbassadorId): void
    {
        if ($previousAmbassadorId !== null || $newAmbassadorId === null) {
            return;
        }

        $ambassador = Ambassador::find($newAmbassadorId);
        if (! $ambassador || (float) $ambassador->one_time_bonus <= 0) {
            return;
        }

        AmbassadorCommission::create([
            'ambassador_id' => $ambassador->id,
            'shop_id' => $shop->id,
            'type' => 'one_time',
            'amount' => $ambassador->one_time_bonus,
            'month' => null,
            'status' => 'unpaid',
        ]);
    }

    /** Closes any existing open assignment for this shop, then opens a new one for $ambassador. Pass null to just unassign (close the open one, start no new one). */
    public static function assign(Shop $shop, ?Ambassador $ambassador, ?string $note = null): void
    {
        AmbassadorShopAssignment::where('shop_id', $shop->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);

        if ($ambassador) {
            AmbassadorShopAssignment::create([
                'shop_id' => $shop->id,
                'ambassador_id' => $ambassador->id,
                'assigned_at' => now(),
                'assigned_by_admin_id' => Auth::guard('admin')->id(),
                'note' => $note,
            ]);
        }
    }

    /** Hooked from SubscriptionPaymentObserver — creates the recurring commission row if this shop currently has an assigned ambassador with a nonzero rate. */
    public static function handleSubscriptionPayment(SubscriptionPayment $payment): void
    {
        $ambassador = $payment->shop?->currentAmbassador();
        if (! $ambassador || (float) $ambassador->recurring_percent <= 0) {
            return;
        }

        $amount = round((float) $payment->amount * (float) $ambassador->recurring_percent / 100, 2);
        if ($amount <= 0) {
            return;
        }

        AmbassadorCommission::create([
            'ambassador_id' => $ambassador->id,
            'shop_id' => $payment->shop_id,
            'type' => 'recurring',
            'amount' => $amount,
            'month' => $payment->month,
            'subscription_payment_id' => $payment->id,
            'status' => 'unpaid',
        ]);
    }
}
