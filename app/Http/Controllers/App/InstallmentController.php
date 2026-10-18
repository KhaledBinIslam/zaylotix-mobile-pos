<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Sale;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A structured payment schedule laid over a sale's own leftover due (see
 * the create_installment_plans migration's docblock for why this never
 * duplicates customers.due bookkeeping) — created once, right after a sale,
 * then tracked here until every installment is paid off or the owner
 * cancels it.
 */
class InstallmentController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'active');

        $plans = InstallmentPlan::with(['customer:id,name,phone', 'items'])
            ->when($tab === 'completed', fn ($q) => $q->where('status', 'completed'))
            ->when($tab === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->when($tab === 'active', fn ($q) => $q->where('status', 'active'))
            ->when($tab === 'overdue', fn ($q) => $q->where('status', 'active')
                ->whereHas('items', fn ($q2) => $q2->where('status', 'pending')->whereDate('due_date', '<', now()->toDateString())))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('App/Installments/Index', [
            'plans' => $plans,
            'tab' => $tab,
        ]);
    }

    /** Called from Sales/Show.vue right after a sale with leftover due — pre-filled with that sale's own numbers. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['nullable', 'exists:sales,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:60'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'start_date' => ['required', 'date'],
            'late_fee_type' => ['nullable', 'in:flat,percent'],
            'late_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($data['sale_id']) && InstallmentPlan::where('sale_id', $data['sale_id'])->exists()) {
            abort(422, 'এই বিক্রয়ের জন্য ইতিমধ্যে একটি কিস্তির প্ল্যান আছে।');
        }

        try {
            $plan = DB::transaction(function () use ($data) {
                // Confirmed bug (see docs/FIX_PLAN.md Phase 7): total_amount
                // had no upper bound at all — a plan could be scheduled for
                // far more than the customer (or that specific sale)
                // actually owes. Since creating a plan is deliberately pure
                // bookkeeping and never touches customers.due itself (see
                // this table's own migration docblock), an over-scheduled
                // plan would sit with permanently unpayable installments —
                // PaymentController already hard-blocks collecting past the
                // real due, so those installments could never be paid off,
                // and ApplyInstallmentLateFees would keep charging late fees
                // on debt that never existed. Locked so this can't race a
                // concurrent return/void/payment changing the same due out
                // from under this check.
                $customer = Customer::whereKey($data['customer_id'])->lockForUpdate()->first();
                if (! $customer) {
                    abort(422, 'কাস্টমার খুঁজে পাওয়া যায়নি।');
                }
                $maxAllowed = (float) $customer->due;

                if (! empty($data['sale_id'])) {
                    $sale = Sale::whereKey($data['sale_id'])->lockForUpdate()->with('payments')->first();
                    if (! $sale) {
                        abort(422, 'বিক্রয়টি খুঁজে পাওয়া যায়নি।');
                    }
                    $tendered = (float) $sale->payments->sum('amount');
                    $saleDue = max(0.0, round((float) $sale->total - $tendered, 2));
                    // the lower of the two — a sale's own due can never
                    // legitimately exceed the customer's total due; taking
                    // the minimum is the more conservative (safer) ceiling
                    // if the two have ever drifted apart
                    $maxAllowed = min($maxAllowed, $saleDue);
                }

                if ((float) $data['total_amount'] - $maxAllowed > 0.01) {
                    throw ValidationException::withMessages([
                        'total_amount' => 'এত বেশি বাকির উপর কিস্তি করা যাবে না। সর্বোচ্চ: '.number_format($maxAllowed, 2),
                    ]);
                }

                $plan = InstallmentPlan::create([
                    'shop_id' => Tenancy::id(),
                    'customer_id' => $data['customer_id'],
                    'sale_id' => $data['sale_id'] ?? null,
                    'total_amount' => $data['total_amount'],
                    'down_payment' => $data['down_payment'] ?? 0,
                    'installment_count' => $data['installment_count'],
                    'frequency' => $data['frequency'],
                    'start_date' => $data['start_date'],
                    'late_fee_type' => $data['late_fee_type'] ?? null,
                    'late_fee_amount' => $data['late_fee_amount'] ?? null,
                    'status' => 'active',
                    'notes' => $data['notes'] ?? null,
                    'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                ]);

                $count = (int) $data['installment_count'];
                $total = (float) $data['total_amount'];
                $each = round($total / $count, 2);
                $date = \Carbon\Carbon::parse($data['start_date']);

                for ($i = 0; $i < $count; $i++) {
                    // the last line absorbs whatever rounding left behind,
                    // so the schedule's own sum always equals total_amount
                    // exactly
                    $amount = $i === $count - 1 ? round($total - ($each * ($count - 1)), 2) : $each;

                    InstallmentScheduleItem::create([
                        'shop_id' => Tenancy::id(),
                        'installment_plan_id' => $plan->id,
                        'due_date' => $date->copy()->toDateString(),
                        'amount' => $amount,
                    ]);

                    $date = $data['frequency'] === 'weekly' ? $date->copy()->addWeek() : $date->copy()->addMonthNoOverflow();
                }

                return $plan;
            });
        } catch (QueryException $e) {
            // the unique index on sale_id (added alongside this fix) is the
            // real guard against the race the exists() check above can't
            // close on its own — a concurrent duplicate submit lands here
            // as a friendly error instead of a raw 500
            if ((int) $e->getCode() === 23000) {
                abort(422, 'এই বিক্রয়ের জন্য ইতিমধ্যে একটি কিস্তির প্ল্যান আছে।');
            }
            throw $e;
        }

        Activity::log('installment.create', "{$plan->customer->name}-এর জন্য {$data['installment_count']}টি কিস্তির প্ল্যান তৈরি হয়েছে।", $plan, ['total_amount' => $data['total_amount']]);

        return redirect()->route('app.installments.show', $plan->id)->with('success', 'কিস্তির প্ল্যান তৈরি হয়েছে।');
    }

    public function show(InstallmentPlan $plan)
    {
        $plan->load(['items' => fn ($q) => $q->orderBy('due_date'), 'customer', 'sale:id,invoice_no,date,total', 'payments' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id')]);

        return Inertia::render('App/Installments/Show', [
            'plan' => $plan,
            'scheduledTotal' => $plan->scheduledTotal(),
            'totalPaid' => $plan->totalPaid(),
            'totalLateFees' => $plan->totalLateFees(),
            'remainingBalance' => $plan->remainingBalance(),
        ]);
    }

    public function cancel(InstallmentPlan $plan)
    {
        if ($plan->status !== 'active') {
            abort(422, 'এই প্ল্যানটি আর সক্রিয় নেই।');
        }

        $plan->update(['status' => 'cancelled']);
        Activity::log('installment.cancel', "{$plan->customer->name}-এর কিস্তির প্ল্যান বাতিল করা হয়েছে।", $plan);

        return back()->with('success', 'প্ল্যান বাতিল করা হয়েছে।');
    }
}
