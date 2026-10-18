<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Payment;
use App\Support\InstallmentAllocation;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    /** Collect a due payment from a customer; moves cash into the shop's balance atomically. */
    public function store(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'in:cash,bkash,nagad'],
            // tagging a payment to a plan never changes the due math below —
            // it only tells InstallmentAllocation which installment(s) this
            // money actually covers (see its own docblock)
            'installment_plan_id' => ['nullable', Rule::exists('installment_plans', 'id')->where('customer_id', $customer->id)],
            // optional — which invoice this payment is actually for (see
            // invoices() below). Never changes the due math either; a
            // payment with no sale_id still works exactly as it always has,
            // this just makes a later per-invoice view possible going
            // forward (see docs/FIX_PLAN.md Phase 3.2).
            'sale_id' => ['nullable', Rule::exists('sales', 'id')->where('customer_id', $customer->id)],
        ]);

        $amount = (float) $data['amount'];

        DB::transaction(function () use ($customer, $amount, $data) {
            // Customer::whereKey(...)->lockForUpdate() — NOT
            // $customer->lockForUpdate() — because lockForUpdate() isn't a
            // real Model method; calling it on an already-hydrated instance
            // forwards to a fresh query with no primary-key WHERE clause,
            // silently locking (and then updating) every customer row for
            // the shop instead of just this one.
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();

            // Re-check against the row we just locked, not the pre-request
            // value on $customer — two payments submitted at nearly the same
            // moment must not both pass a check against the same stale due.
            if ($amount > (float) $locked->due) {
                throw ValidationException::withMessages([
                    'amount' => "এত বেশি বাকি নেই। {$locked->name}-এর সর্বোচ্চ বাকি: ".number_format((float) $locked->due, 2),
                ]);
            }

            $dueBefore = (float) $locked->due;
            $locked->decrement('due', $amount);

            $locked->payments()->create([
                'shop_id' => Tenancy::id(),
                'sale_id' => $data['sale_id'] ?? null,
                'installment_plan_id' => $data['installment_plan_id'] ?? null,
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'amount' => $amount,
                // snapshotted here, not re-derived later from other rows —
                // see the migration's own comment on why a ledger row's
                // before/after has to be captured at the moment it happens
                'due_before' => $dueBefore,
                'due_after' => $dueBefore - $amount,
                'method' => $data['method'] ?? 'cash',
                'date' => now()->toDateString(),
            ]);

            if (($data['method'] ?? 'cash') === 'cash') {
                \App\Models\Shop::whereKey(Tenancy::id())->increment('cash_balance', $amount);
            } else {
                \App\Models\Shop::whereKey(Tenancy::id())->increment('bank_balance', $amount);
            }

            if (! empty($data['installment_plan_id'])) {
                $plan = InstallmentPlan::whereKey($data['installment_plan_id'])->lockForUpdate()->first();
                if ($plan) {
                    InstallmentAllocation::apply($plan, $amount);
                }
            }
        });

        return back()->with('success', 'Payment recorded.');
    }

    /** Per-customer payment ledger — date, amount, and the due snapshot right before/after that specific collection. */
    public function history(Customer $customer)
    {
        $this->authorize('view', $customer);

        $payments = $customer->payments()->with('user:id,name')->latest('date')->latest('id')->get([
            'id', 'customer_id', 'user_id', 'amount', 'due_before', 'due_after', 'method', 'date',
        ]);

        return response()->json(['payments' => $payments]);
    }

    /**
     * Phase 3.2 (see docs/FIX_PLAN.md) — a per-invoice reference view, NOT a
     * second source of truth: customer.due stays the one authoritative
     * total, always shown alongside this. due_at_checkout is exact and
     * immutable (total minus whatever was tendered at the moment of sale);
     * tagged_paid only ever reflects payments explicitly collected against
     * THIS invoice via sale_id (see store() above) — a historical lump
     * payment collected before that existed, or any payment collected
     * without picking an invoice, is correctly NOT counted here, since
     * there's no reliable way to know which invoice(s) it actually applied
     * to. That's why this never claims to show a current "still owed"
     * figure per invoice — only what was due at checkout and what's since
     * been explicitly tagged to it.
     */
    public function invoices(Customer $customer)
    {
        $this->authorize('view', $customer);

        $invoices = $customer->sales()
            ->whereNull('voided_at')
            ->with('payments')
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(function ($sale) {
                $tendered = (float) $sale->payments->sum('amount');
                $dueAtCheckout = max(0, round((float) $sale->total - $tendered, 2));

                return [
                    'id' => $sale->id,
                    'invoice_no' => $sale->invoice_no,
                    'date' => $sale->date,
                    'total' => (float) $sale->total,
                    'due_at_checkout' => $dueAtCheckout,
                    'tagged_paid' => (float) Payment::where('sale_id', $sale->id)->sum('amount'),
                ];
            })
            ->filter(fn ($row) => $row['due_at_checkout'] > 0)
            ->values();

        return response()->json(['invoices' => $invoices]);
    }

    public function full(Customer $customer)
    {
        $this->authorize('update', $customer);

        DB::transaction(function () use ($customer) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();
            $amount = (float) $locked->due;
            $locked->update(['due' => 0]);

            $locked->payments()->create([
                'shop_id' => Tenancy::id(),
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'amount' => $amount,
                'due_before' => $amount,
                'due_after' => 0,
                'method' => 'cash',
                'date' => now()->toDateString(),
            ]);

            \App\Models\Shop::whereKey(Tenancy::id())->increment('cash_balance', $amount);
        });

        return back()->with('success', 'Marked fully paid.');
    }
}
