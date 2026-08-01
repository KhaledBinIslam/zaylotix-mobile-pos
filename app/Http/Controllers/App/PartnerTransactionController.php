<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerTransactionController extends Controller
{
    public function store(Request $request, Partner $partner)
    {
        $this->authorize('update', $partner);

        $data = $request->validate([
            'type' => ['required', 'in:investment,withdrawal'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($partner, $data) {
            $locked = Partner::whereKey($partner->id)->lockForUpdate()->first();
            $amount = (float) $data['amount'];
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';

            if ($data['type'] === 'investment') {
                $shop->increment($field, $amount);
                $shop->increment('capital', $amount);
                $locked->increment('invested_amount', $amount);
            } else {
                // A withdrawal is deliberately allowed even past this
                // partner's computed profit share — same "don't clamp,
                // surface the real number" reasoning as everywhere else in
                // accounts (ExpenseController, CashTransactionController):
                // an owner drawing out more than they're technically owed is
                // a real business event the books must reflect, not silently block.
                $shop->decrement($field, $amount);
                $locked->increment('withdrawn_amount', $amount);
            }

            $locked->transactions()->create([
                'type' => $data['type'], 'amount' => $amount, 'method' => $data['method'],
                'note' => $data['note'] ?? null, 'date' => now()->toDateString(),
            ]);

            $verb = $data['type'] === 'investment' ? 'বিনিয়োগ করেছেন' : 'উত্তোলন করেছেন';
            Activity::log('partner.'.$data['type'], "'{$locked->name}' ".number_format($amount, 2)." টাকা {$verb}।", $locked, ['amount' => $amount]);
        });

        return back()->with('success', 'Partner transaction recorded.');
    }
}
