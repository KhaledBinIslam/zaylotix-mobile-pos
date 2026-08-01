<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CashTransactionController extends Controller
{
    public function index()
    {
        $transactions = CashTransaction::with('user:id,name')->latest('date')->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('App/CashLedger/Index', [
            'transactions' => $transactions,
            'cash' => (float) Tenancy::shop()->cash_balance,
            'bank' => (float) Tenancy::shop()->bank_balance,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:deposit,withdraw,cash_to_bank,bank_to_cash,bank_to_bank'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'from_label' => ['nullable', 'string', 'max:100'],
            'to_label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $amount = (float) $data['amount'];

            // Deliberately allowed to go negative, same reasoning as
            // ExpenseController — clamping to 0 would silently break the
            // balance-sheet invariant instead of surfacing a real shortfall.
            switch ($data['type']) {
                case 'deposit':
                    $shop->increment('cash_balance', $amount);
                    break;
                case 'withdraw':
                    $shop->decrement('cash_balance', $amount);
                    break;
                case 'cash_to_bank':
                    $shop->decrement('cash_balance', $amount);
                    $shop->increment('bank_balance', $amount);
                    break;
                case 'bank_to_cash':
                    $shop->decrement('bank_balance', $amount);
                    $shop->increment('cash_balance', $amount);
                    break;
                case 'bank_to_bank':
                    // Both named accounts already live inside the one
                    // aggregate bank_balance bucket, so a bank-to-bank move
                    // doesn't change the shop's total — it's logged purely
                    // so the owner has a dated record of which of their own
                    // accounts the money is in.
                    break;
            }

            $transaction = CashTransaction::create([
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'type' => $data['type'],
                'amount' => $amount,
                'from_label' => $data['from_label'] ?? null,
                'to_label' => $data['to_label'] ?? null,
                'note' => $data['note'] ?? null,
                'date' => now()->toDateString(),
            ]);

            Activity::log('cash.'.$data['type'], $this->describe($data['type'], $amount), $transaction, ['amount' => $amount]);
        });

        return back()->with('success', 'Transaction recorded.');
    }

    private function describe(string $type, float $amount): string
    {
        $formatted = number_format($amount, 2);

        return match ($type) {
            'deposit' => "ক্যাশে {$formatted} টাকা জমা হয়েছে।",
            'withdraw' => "ক্যাশ থেকে {$formatted} টাকা উত্তোলন হয়েছে।",
            'cash_to_bank' => "ক্যাশ থেকে ব্যাংকে {$formatted} টাকা স্থানান্তর হয়েছে।",
            'bank_to_cash' => "ব্যাংক থেকে ক্যাশে {$formatted} টাকা স্থানান্তর হয়েছে।",
            'bank_to_bank' => "ব্যাংক থেকে ব্যাংকে {$formatted} টাকা স্থানান্তর হয়েছে।",
            default => "{$formatted} টাকার লেনদেন হয়েছে।",
        };
    }
}
