<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierReturnController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('shop_id', Tenancy::id())],
            'supplier' => ['nullable', 'string', 'max:255'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'qty' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'settlement_method' => ['required', 'in:cash,bank,payable'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($data['settlement_method'] === 'payable' && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'settlement_method' => 'বকেয়া থেকে বাদ দিতে হলে তালিকা থেকে সাপ্লায়ার বেছে নিন।',
            ]);
        }

        DB::transaction(function () use ($data) {
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();

            // same invariant-safety reasoning as DamageController/ReturnController
            if ($product->variants()->exists()) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name} ভ্যারিয়েন্ট পণ্য — এখানে ফেরত দেওয়া যাবে না, স্টক পেজ থেকে নির্দিষ্ট ভ্যারিয়েন্টের স্টক কমান।",
                ]);
            }

            // unlike a customer return (bounded by what was ever sold), a
            // supplier return is bounded by what's physically still on the
            // shelf right now — can't send back more than you actually have
            if ($data['qty'] > $product->stock) {
                throw ValidationException::withMessages([
                    'qty' => "বর্তমান স্টকের বেশি ফেরত দেওয়া যাবে না (আছে {$product->stock})।",
                ]);
            }

            $product->decrement('stock', $data['qty']);

            SupplierReturn::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier' => $data['supplier'] ?? null,
                'product_id' => $product->id,
                'qty' => $data['qty'],
                'reason' => $data['reason'] ?? null,
                'settlement_method' => $data['settlement_method'],
                'amount' => $data['amount'],
                'date' => now()->toDateString(),
            ]);

            if ($data['settlement_method'] === 'payable') {
                Supplier::whereKey($data['supplier_id'])->decrement('payable', $data['amount']);
            } else {
                $field = $data['settlement_method'] === 'cash' ? 'cash_balance' : 'bank_balance';
                Shop::whereKey(Tenancy::id())->increment($field, $data['amount']);
            }

            Activity::log('supplier.return', "'{$product->name}' সাপ্লায়ারকে ফেরত দেওয়া হয়েছে — {$data['qty']} ইউনিট, ".number_format((float) $data['amount'], 2).' টাকা।', $product, [
                'qty' => $data['qty'], 'amount' => $data['amount'],
            ]);
        });

        return back()->with('success', 'সাপ্লায়ার রিটার্ন রেকর্ড করা হয়েছে।');
    }
}
