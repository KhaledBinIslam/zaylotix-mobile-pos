<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PartnerController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();
        $partners = Partner::with('transactions')->orderBy('joined_date')->get();

        // Same retained-profit formula as AccountsController — undistributed
        // profit is what's actually available to split among partners by
        // ownership share, not the shop's raw cash/bank balance.
        $stockValue = (float) \App\Models\Product::selectRaw('COALESCE(SUM(cost * stock), 0) as v')->value('v');
        $receivable = (float) Customer::sum('due');
        $payable = (float) Supplier::sum('payable')
            + (float) Purchase::whereNull('supplier_id')->where('method', 'credit')->sum('amount');
        $assets = (float) $shop->cash_balance + (float) $shop->bank_balance + $stockValue + $receivable;
        $netWorth = $assets - $payable;
        $retained = $netWorth - (float) $shop->capital;

        $partnerRows = $partners->map(function (Partner $p) use ($retained) {
            $share = round($retained * ((float) $p->ownership_percent / 100), 2);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'ownership_percent' => (float) $p->ownership_percent,
                'invested_amount' => (float) $p->invested_amount,
                'withdrawn_amount' => (float) $p->withdrawn_amount,
                'joined_date' => $p->joined_date->toDateString(),
                'profit_share' => $share,
                // what this partner is currently entitled to if the
                // business were settled today: what they put in, plus their
                // slice of undistributed profit, minus what they already took out
                'net_position' => round((float) $p->invested_amount + $share - (float) $p->withdrawn_amount, 2),
            ];
        });

        return Inertia::render('App/Partners/Index', [
            'partners' => $partnerRows,
            'totalOwnershipPercent' => round((float) $partners->sum('ownership_percent'), 2),
            'retainedProfit' => round($retained, 2),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'ownership_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'invested_amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required_with:invested_amount', 'in:cash,bank'],
        ]);

        $existingTotal = (float) Partner::sum('ownership_percent');
        if ($existingTotal + (float) $data['ownership_percent'] > 100) {
            throw ValidationException::withMessages([
                'ownership_percent' => 'মোট মালিকানা ১০০% এর বেশি হতে পারবে না। বর্তমানে বাকি আছে: '.number_format(100 - $existingTotal, 2).'%',
            ]);
        }

        DB::transaction(function () use ($data) {
            $invested = (float) ($data['invested_amount'] ?? 0);

            $partner = Partner::create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'ownership_percent' => $data['ownership_percent'],
                'invested_amount' => $invested,
                'withdrawn_amount' => 0,
                'joined_date' => now()->toDateString(),
            ]);

            if ($invested > 0) {
                $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
                $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
                $shop->increment($field, $invested);
                $shop->increment('capital', $invested);

                $partner->transactions()->create([
                    'type' => 'investment', 'amount' => $invested, 'method' => $data['method'], 'date' => now()->toDateString(),
                ]);
            }
        });

        return back()->with('success', 'Partner added.');
    }
}
