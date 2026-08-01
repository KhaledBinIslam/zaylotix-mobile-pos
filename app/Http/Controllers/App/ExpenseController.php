<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Expenses/Index', [
            // capped like SalesController — an unbounded ->get() here would
            // load a shop's entire multi-year expense history on every visit
            'expenses' => Expense::with('category')->latest('id')->limit(100)->get(),
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'total' => (float) Expense::sum('amount'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('shop_id', Tenancy::id())],
            'new_category_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank'],
        ]);

        DB::transaction(function () use ($data) {
            $categoryId = $data['expense_category_id'] ?? null;
            if (! $categoryId && ! empty($data['new_category_name'])) {
                $categoryId = ExpenseCategory::create([
                    'shop_id' => Tenancy::id(),
                    'name' => $data['new_category_name'],
                    'name_en' => $data['new_category_name'],
                    'emoji' => '💰',
                ])->id;
            }

            Expense::create([
                'expense_category_id' => $categoryId,
                'memo' => $data['memo'] ?? null,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'date' => now()->toDateString(),
            ]);

            // Deliberately allowed to go negative rather than clamped to 0 —
            // an expense larger than the recorded balance means the shop is
            // genuinely short, and silently flooring it at 0 would make the
            // balance sheet stop matching Expense::sum('amount') with no way
            // to detect the drift.
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
            Shop::whereKey(Tenancy::id())->decrement($field, $data['amount']);
        });

        return back()->with('success', 'Expense added.');
    }
}
