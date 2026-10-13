<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $plan = DB::transaction(function () use ($data) {
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
                // the last line absorbs whatever rounding left behind, so
                // the schedule's own sum always equals total_amount exactly
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
