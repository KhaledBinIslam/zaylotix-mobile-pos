<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ArraySheetExport;
use App\Http\Controllers\Controller;
use App\Models\Ambassador;
use App\Models\AmbassadorCommission;
use App\Models\Shop;
use App\Support\AdminActivity;
use App\Support\AmbassadorCommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Super-admin-only (see routes/admin.php) — sales-agent/ambassador tracking.
 * Every commission row is written exclusively through
 * AmbassadorCommissionService, never directly here.
 */
class AmbassadorController extends Controller
{
    public function index()
    {
        // Khaled's explicit ask: the list only shows who they are and how
        // to reach them — rates/shops-brought/unpaid-total are still real,
        // live data (see show()), just not dumped on this admin list view
        // alongside a per-row DB hit each for unpaid_total/brought_this_month
        $ambassadors = Ambassador::orderBy('name')
            ->get(['id', 'name', 'employee_id', 'phone', 'email', 'referral_code'])
            ->map(fn (Ambassador $a) => [...$a->toArray(), 'signup_url' => $a->signupUrl()]);

        return Inertia::render('Admin/Ambassadors/Index', [
            'ambassadors' => $ambassadors,
            // main shops only, same convention as show()'s own shop picker —
            // a branch's own ambassador (if ever needed) is still reachable
            // via that branch's own Edit page
            'shops' => Shop::withoutGlobalScopes()->whereNull('parent_shop_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Quick-add — Khaled's explicit ask: just who (name + employee ID) and
     * which shop, nothing money-related at creation time. Rates/phone/
     * email/note are still editable afterward from the ambassador's own
     * detail page (show()) if ever needed; this only trims what the
     * creation step itself asks for.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:50'],
            'shop_id' => ['required', 'exists:shops,id'],
        ]);

        $ambassador = Ambassador::create([
            'name' => $data['name'],
            'employee_id' => $data['employee_id'],
            'referral_code' => $this->generateUniqueReferralCode($data['employee_id']),
        ]);

        $shop = Shop::withoutGlobalScopes()->findOrFail($data['shop_id']);
        AmbassadorCommissionService::assign($shop, $ambassador);

        AdminActivity::log('ambassador.create', "Added ambassador '{$ambassador->name}' ({$ambassador->employee_id}) for shop '{$shop->name}'.", $ambassador);

        return back()->with('success', "Ambassador '{$ambassador->name}' added for '{$shop->name}'.");
    }

    /** Human-readable where possible (the employee ID itself, sanitized) — falls back to a random suffix only on a genuine collision, which in practice never happens since employee_id is already effectively unique. */
    private function generateUniqueReferralCode(string $employeeId): string
    {
        $base = Str::upper(Str::slug($employeeId, ''));
        if ($base === '') {
            $base = 'AMB';
        }

        $code = $base;
        while (Ambassador::where('referral_code', $code)->exists()) {
            $code = $base.Str::upper(Str::random(3));
        }

        return $code;
    }

    public function update(Request $request, Ambassador $ambassador)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'one_time_bonus' => ['required', 'numeric', 'min:0'],
            'recurring_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $ambassador->update($data);
        AdminActivity::log('ambassador.update', "Updated ambassador '{$ambassador->name}'.", $ambassador);

        return back()->with('success', 'Ambassador updated.');
    }

    public function show(Ambassador $ambassador)
    {
        $broughtShops = $ambassador->broughtShops()->withoutGlobalScopes()->get(['id', 'name', 'phone', 'created_at']);
        $currentShops = $ambassador->currentShops()->withoutGlobalScopes()->get(['shops.id', 'shops.name', 'shops.phone']);

        $commissions = $ambassador->commissions()
            ->with(['shop' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->get();

        // grouped by month (one_time rows carry no month -- bucketed under
        // the month they were actually created instead, so every taka owed
        // still shows up somewhere in the monthly report)
        $monthly = $commissions->groupBy(fn (AmbassadorCommission $c) => $c->month ?? $c->created_at->format('Y-m'))
            ->map(function ($rows, $month) {
                return [
                    'month' => $month,
                    'one_time_count' => $rows->where('type', 'one_time')->count(),
                    'one_time_amount' => (float) $rows->where('type', 'one_time')->sum('amount'),
                    'recurring_amount' => (float) $rows->where('type', 'recurring')->sum('amount'),
                    'total' => (float) $rows->sum('amount'),
                    'unpaid' => (float) $rows->where('status', 'unpaid')->sum('amount'),
                    'paid' => (float) $rows->where('status', 'paid')->sum('amount'),
                ];
            })
            ->sortByDesc('month')
            ->values();

        return Inertia::render('Admin/Ambassadors/Show', [
            'ambassador' => [...$ambassador->toArray(), 'signup_url' => $ambassador->signupUrl()],
            'broughtShops' => $broughtShops,
            'currentShops' => $currentShops,
            'commissions' => $commissions,
            'monthly' => $monthly,
            'allShops' => Shop::withoutGlobalScopes()->whereNull('parent_shop_id')->orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    /** Set from a shop's own Edit page (Admin/Shops/Edit.vue) — handles both "who brought this shop" (one-time, set-once) and "who's currently responsible" (recurring, reassignable) in one request. */
    public function assignToShop(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'brought_by_ambassador_id' => ['nullable', 'exists:ambassadors,id'],
            'current_ambassador_id' => ['nullable', 'exists:ambassadors,id'],
        ]);

        $previousBroughtBy = $shop->brought_by_ambassador_id;
        if (array_key_exists('brought_by_ambassador_id', $data) && $data['brought_by_ambassador_id'] != $previousBroughtBy) {
            $shop->update(['brought_by_ambassador_id' => $data['brought_by_ambassador_id']]);
            AmbassadorCommissionService::handleBroughtByChange($shop, $previousBroughtBy, $data['brought_by_ambassador_id']);
        }

        // only touch the "currently assigned" assignment when this request
        // actually included that field -- a request that only sends
        // brought_by_ambassador_id (e.g. from a future "mark as brought by"
        // shortcut) must never silently unassign the existing one
        if (array_key_exists('current_ambassador_id', $data)) {
            $currentAmbassador = $data['current_ambassador_id'] ? Ambassador::find($data['current_ambassador_id']) : null;
            AmbassadorCommissionService::assign($shop, $currentAmbassador);
        }

        AdminActivity::log('ambassador.assignShop', "Updated ambassador assignment for shop '{$shop->name}'.", $shop);

        return back()->with('success', 'Ambassador assignment updated.');
    }

    public function markPaid(Request $request, Ambassador $ambassador)
    {
        $data = $request->validate([
            'month' => ['nullable', 'string'],
            'commission_ids' => ['nullable', 'array'],
            'commission_ids.*' => ['integer', 'exists:ambassador_commissions,id'],
        ]);

        $query = $ambassador->commissions()->where('status', 'unpaid');
        if (! empty($data['commission_ids'])) {
            $query->whereIn('id', $data['commission_ids']);
        } elseif (! empty($data['month'])) {
            // avoid DATE_FORMAT (MySQL-only, breaks on SQLite in tests) --
            // a plain created_at range works identically on both
            $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->where(fn ($q) => $q->where('month', $data['month'])->orWhere(fn ($q2) => $q2->whereNull('month')->whereBetween('created_at', [$start, $end])));
        } else {
            return back()->with('error', 'কোন মাস বা কমিশন নির্বাচন করা হয়নি।');
        }

        $count = $query->update(['status' => 'paid', 'paid_at' => now(), 'paid_by_admin_id' => Auth::guard('admin')->id()]);

        AdminActivity::log('ambassador.markPaid', "Marked {$count} commission row(s) paid for '{$ambassador->name}'.", $ambassador);

        return back()->with('success', "{$count}টা কমিশন রেকর্ড paid হিসেবে মার্ক করা হলো।");
    }

    public function export(Ambassador $ambassador)
    {
        $rows = $ambassador->commissions()
            ->with(['shop' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->get()
            ->map(fn (AmbassadorCommission $c) => [
                $c->shop?->name ?? '—',
                $c->type,
                $c->month ?? $c->created_at->format('Y-m'),
                (float) $c->amount,
                $c->status,
                $c->paid_at?->toDateString() ?? '—',
            ])->all();

        $filename = Str::slug($ambassador->name).'-commissions-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new ArraySheetExport($ambassador->name, ['Shop', 'Type', 'Month', 'Amount', 'Status', 'Paid on'], $rows),
            $filename
        );
    }
}
