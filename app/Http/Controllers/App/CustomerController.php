<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index()
    {
        // every customer who owes money is actionable — that list must
        // never be truncated, however large. Paid-off customers are just a
        // browse/reference list, so only that half gets capped, at the most
        // recently active 300 (updated_at bumps on every sale/payment).
        $withDue = Customer::where('due', '>', 0)->orderByDesc('due')->get();
        $cleared = Customer::where('due', '<=', 0)->orderByDesc('updated_at')->limit(300)->get();

        return Inertia::render('App/Customers/Index', [
            'customers' => $withDue->concat($cleared)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'due' => ['nullable', 'numeric', 'min:0'],
            // see docs/FIX_PLAN.md's "রেফারেল" design note — only settable
            // at creation (this customer has no id yet, so "referring
            // themselves" is structurally impossible here; there's no
            // edit endpoint that could ever retarget it later either)
            'referred_by_customer_id' => ['nullable', Rule::exists('customers', 'id')->where('shop_id', Tenancy::id())],
            // see docs/FIX_PLAN.md's "ক্রেডিট লিমিট" design note — null
            // (the default) means "use the shop-wide default", not zero
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Same phone-based find-or-create the POS checkout uses — without
        // this, adding a customer whose phone is already on file creates a
        // second record, silently splitting their due/history across two
        // rows (and "search by phone" would then return whichever one wins).
        $phone = $data['phone'] ?? null;
        $existing = $phone ? Customer::where('phone', $phone)->first() : null;

        if ($existing) {
            return back()->withErrors([
                'phone' => "এই নম্বরে আগে থেকেই কাস্টমার আছে: {$existing->name}। নতুন করে যোগ করার দরকার নেই।",
            ]);
        }

        Customer::create([
            'name' => $data['name'],
            'phone' => $phone,
            'due' => $data['due'] ?? 0,
            'referred_by_customer_id' => $data['referred_by_customer_id'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? null,
        ]);

        return back()->with('success', "{$data['name']} added.");
    }

    /** See docs/FIX_PLAN.md's "ক্রেডিট লিমিট" design note — sets this one customer's own override; leaving it blank again falls back to the shop-wide default. */
    public function updateCreditLimit(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validate(['credit_limit' => ['nullable', 'numeric', 'min:0']]);
        $customer->update(['credit_limit' => $data['credit_limit'] ?? null]);

        return back()->with('success', "{$customer->name}-এর ক্রেডিট লিমিট সংরক্ষণ করা হয়েছে।");
    }

    /** Used by the POS checkout sheet to auto-fill an existing customer's name as soon as their phone number is typed. */
    public function lookup(Request $request)
    {
        $phone = trim((string) $request->get('phone', ''));

        $customer = $phone !== '' ? Customer::where('phone', $phone)->first() : null;

        if (! $customer) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'name' => $customer->name,
            'due' => (float) $customer->due,
            'loyalty_points' => $customer->loyalty_points,
            // null = no limit — see docs/FIX_PLAN.md's "ক্রেডিট লিমিট"
            // design note. Harmless to always send; the frontend only
            // shows it when the shop actually has the feature on.
            'credit_limit' => $customer->effectiveCreditLimit(),
        ]);
    }

    /**
     * Used by the POS checkout sheet's name field — the phone-based lookup()
     * above only ever finds a customer the cashier already knows the exact
     * phone number for; this covers the equally common case of recognizing
     * a repeat customer by name instead, especially useful when a customer
     * shares a household phone or the cashier simply doesn't have it handy.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $customers = Customer::where('name', 'like', '%'.$q.'%')
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone', 'due', 'loyalty_points', 'credit_limit', 'shop_id'])
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'due' => (float) $c->due,
                'loyalty_points' => $c->loyalty_points, 'credit_limit' => $c->effectiveCreditLimit(),
            ]);

        return response()->json(['results' => $customers]);
    }
}
