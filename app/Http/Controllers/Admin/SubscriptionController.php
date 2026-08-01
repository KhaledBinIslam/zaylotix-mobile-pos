<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Notifications\PaymentReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    public function index()
    {
        $payments = SubscriptionPayment::with('shop:id,name,phone')
            ->latest('paid_on')
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Subscriptions/Index', [
            'payments' => $payments,
            'shops' => Shop::orderBy('name')->get(['id', 'name', 'phone', 'plan', 'status', 'subscription_expiry']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['required', 'exists:shops,id'],
            'plan' => ['required', 'in:trial,monthly,yearly'],
            'amount' => ['required', 'numeric', 'min:0'],
            'month' => ['required', 'date_format:Y-m'],
            'method' => ['required', 'string', 'max:50'],
            'paid_on' => ['required', 'date'],
            'next_due' => ['nullable', 'date', 'after:paid_on'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['admin_id'] = Auth::guard('admin')->id();
        $payment = SubscriptionPayment::create($data);

        // extend / reactivate the shop's subscription based on this payment
        if (! empty($data['next_due'])) {
            Shop::whereKey($data['shop_id'])->update([
                'subscription_expiry' => $data['next_due'],
                'plan' => $data['plan'],
                'status' => 'active',
            ]);
        }

        $shop = Shop::with('owner')->find($data['shop_id']);
        $shop?->owner?->notify(new PaymentReceived($payment));

        return back()->with('success', 'Payment recorded.');
    }
}
