<?php

namespace App\Observers;

use App\Models\SubscriptionPayment;
use App\Support\AmbassadorCommissionService;

class SubscriptionPaymentObserver
{
    /** Covers every way a payment gets recorded (admin manual entry, bKash/SSLCommerz auto-renewal webhook) from one place. */
    public function created(SubscriptionPayment $payment): void
    {
        AmbassadorCommissionService::handleSubscriptionPayment($payment);
    }
}
