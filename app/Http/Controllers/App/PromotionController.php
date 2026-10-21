<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PromotionController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Promotions/Index', [
            'promotions' => Promotion::with(['buyProduct:id,name,emoji', 'getProduct:id,name,emoji'])->orderByDesc('id')->get(),
            'products' => Product::orderBy('name')->get(['id', 'name', 'emoji', 'price']),
        ]);
    }

    private function rules(?Promotion $promotion = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:bogo,coupon'],
            'active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],

            'code' => [
                'required_if:type,coupon', 'nullable', 'string', 'max:50',
                Rule::unique('promotions', 'code')->where('shop_id', Tenancy::id())->ignore($promotion?->id),
            ],
            'discount_type' => ['required_if:type,coupon', 'nullable', 'in:percent,fixed'],
            'discount_value' => ['required_if:type,coupon', 'nullable', 'numeric', 'min:0.01'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],

            'buy_product_id' => ['required_if:type,bogo', 'nullable', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'buy_qty' => ['required_if:type,bogo', 'nullable', 'integer', 'min:1'],
            'get_product_id' => ['nullable', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'get_qty' => ['required_if:type,bogo', 'nullable', 'integer', 'min:1'],
            'get_discount_percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
        ];
    }

    private function guardPercentRange(array $data): void
    {
        if (($data['discount_type'] ?? null) === 'percent' && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'শতাংশ ১০০-এর বেশি হতে পারবে না।']);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->guardPercentRange($data);

        $promotion = Promotion::create($this->fields($data));

        $this->syncToBranchesIfShared($promotion);

        return back()->with('success', 'অফার যোগ করা হয়েছে।');
    }

    public function update(Request $request, Promotion $promotion)
    {
        $data = $request->validate($this->rules($promotion));
        $this->guardPercentRange($data);

        // see docs/FIX_PLAN.md's "ব্রাঞ্চ-জুড়ে প্রোমোশন পুলিং" design note —
        // a branch's own linked copy has its shared fields owned by the
        // main shop from now on; only `active` (pause/resume locally)
        // survives an edit made here. Silent, not an error — same
        // defense-in-depth the linked-Product lock already uses.
        if ($promotion->master_promotion_id) {
            $promotion->update(['active' => $data['active'] ?? true]);

            return back()->with('success', 'অফার হালনাগাদ করা হয়েছে।');
        }

        $promotion->update($this->fields($data));

        $this->syncToBranchesIfShared($promotion);

        return back()->with('success', 'অফার হালনাগাদ করা হয়েছে।');
    }

    public function destroy(Promotion $promotion)
    {
        if ($promotion->master_promotion_id) {
            abort(422, 'এটি ব্রাঞ্চ থেকে মুছে ফেলা যাবে না — মূল দোকান থেকে মুছুন।');
        }

        Promotion::withoutGlobalScopes()->where('master_promotion_id', $promotion->id)->delete();
        $promotion->delete();

        return back()->with('success', 'অফার মুছে ফেলা হয়েছে।');
    }

    /**
     * See docs/FIX_PLAN.md's "ব্রাঞ্চ-জুড়ে প্রোমোশন পুলিং" design note —
     * only a main shop (no parent_shop_id) with the opt-in
     * shared_promotions feature ever propagates, and only a `coupon`
     * (a BOGO's buy/get product ids would need the same per-branch
     * mapping CatalogSync already does for StockTransfer, deliberately
     * deferred) — a no-op for every other shop/type.
     */
    private function syncToBranchesIfShared(Promotion $promotion): void
    {
        $shop = Tenancy::shop();
        if ($promotion->type !== 'coupon' || $shop->parent_shop_id || ! $shop->hasFeature('shared_promotions')) {
            return;
        }

        $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $shop->id)->get();
        $attrs = [
            'name' => $promotion->name, 'type' => $promotion->type, 'active' => $promotion->active,
            'code' => $promotion->code, 'discount_type' => $promotion->discount_type, 'discount_value' => $promotion->discount_value,
            'min_purchase' => $promotion->min_purchase, 'usage_limit' => $promotion->usage_limit,
            'starts_at' => $promotion->starts_at, 'expires_at' => $promotion->expires_at,
            'master_promotion_id' => $promotion->id,
        ];

        foreach ($branches as $branch) {
            $existing = Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_promotion_id', $promotion->id)->first();
            if ($existing) {
                // used_count deliberately excluded -- each branch's own usage tally is never overwritten by a sync
                $existing->update($attrs);
            } else {
                Promotion::create(['shop_id' => $branch->id, 'used_count' => 0, ...$attrs]);
            }
        }
    }

    private function fields(array $data): array
    {
        $isBogo = $data['type'] === 'bogo';

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'active' => $data['active'] ?? true,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'code' => $isBogo ? null : $data['code'],
            'discount_type' => $isBogo ? null : $data['discount_type'],
            'discount_value' => $isBogo ? null : $data['discount_value'],
            'min_purchase' => $isBogo ? null : ($data['min_purchase'] ?? null),
            'usage_limit' => $isBogo ? null : ($data['usage_limit'] ?? null),
            'buy_product_id' => $isBogo ? $data['buy_product_id'] : null,
            'buy_qty' => $isBogo ? $data['buy_qty'] : null,
            'get_product_id' => $isBogo ? ($data['get_product_id'] ?? $data['buy_product_id']) : null,
            'get_qty' => $isBogo ? $data['get_qty'] : null,
            'get_discount_percent' => $isBogo ? ($data['get_discount_percent'] ?? 100) : null,
        ];
    }
}
