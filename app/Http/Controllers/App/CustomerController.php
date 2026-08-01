<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
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
        ]);

        return back()->with('success', "{$data['name']} added.");
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
        ]);
    }
}
