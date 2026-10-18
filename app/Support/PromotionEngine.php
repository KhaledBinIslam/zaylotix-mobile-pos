<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Validation\ValidationException;

/**
 * Two promotion styles, kept out of PosController::checkout() itself so
 * that already-heavily-tested method only grows a couple of call sites
 * instead of a second pricing engine inline.
 *
 *  - BOGO/combo: auto-applied, no code. "Buy N of product A, get M of
 *    product B free/discounted" — B defaults to A for a plain "buy 2 get 1
 *    free", or can differ for a combo like "buy 1 burger get 1 drink free".
 *    Only plain product lines participate (no pack-unit or variant lines —
 *    their qty isn't safely comparable to a promotion's buy/get quantities
 *    defined in base units).
 *  - Coupon: a code the cashier types in, a flat/percent discount off the
 *    whole cart, gated by an optional minimum purchase, date window, and
 *    redemption cap.
 */
class PromotionEngine
{
    /**
     * @param  array  $items  the raw (pre-resolution) checkout item list
     * @return array{discounts: array<int, float>, triggeredIds: array<int>}
     *               discounts is keyed by the item's index in $items
     */
    public static function computeAutoLineDiscounts(array $items, int $shopId): array
    {
        $promotions = Promotion::where('type', 'bogo')->where('active', true)->get()
            ->filter(fn ($p) => $p->isWithinDateWindow())
            ->values();

        if ($promotions->isEmpty()) {
            return ['discounts' => [], 'triggeredIds' => []];
        }

        $qtyByProduct = [];
        foreach ($items as $item) {
            if (! empty($item['product_unit_id']) || ! empty($item['product_variant_id'])) {
                continue;
            }
            $pid = (int) $item['product_id'];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int) $item['qty'];
        }

        $discounts = [];
        $triggeredIds = [];

        foreach ($promotions as $promo) {
            $buyQty = $promo->buy_qty ?: 1;
            $rewardProductId = (int) ($promo->get_product_id ?: $promo->buy_product_id);
            $qtyBought = $qtyByProduct[(int) $promo->buy_product_id] ?? 0;

            if ($qtyBought < $buyQty) {
                continue;
            }

            $times = intdiv($qtyBought, $buyQty);
            $freeRemaining = $times * ($promo->get_qty ?: 1);
            // can't give away more free units than the reward product's own
            // qty actually present in the cart
            $freeRemaining = min($freeRemaining, $qtyByProduct[$rewardProductId] ?? 0);

            if ($freeRemaining <= 0) {
                continue;
            }

            $pct = (float) ($promo->get_discount_percent ?? 100);
            $product = Product::find($rewardProductId);
            if (! $product) {
                continue;
            }

            $applied = false;
            foreach ($items as $index => $item) {
                if ($freeRemaining <= 0) {
                    break;
                }
                if (! empty($item['product_unit_id']) || ! empty($item['product_variant_id'])) {
                    continue;
                }
                if ((int) $item['product_id'] !== $rewardProductId) {
                    continue;
                }

                $freeInThisLine = min($freeRemaining, (int) $item['qty']);
                $lineDiscount = round((float) $product->price * $freeInThisLine * $pct / 100, 2);

                $discounts[$index] = ($discounts[$index] ?? 0) + $lineDiscount;
                $freeRemaining -= $freeInThisLine;
                $applied = true;
            }

            if ($applied) {
                $triggeredIds[] = $promo->id;
            }
        }

        return ['discounts' => $discounts, 'triggeredIds' => $triggeredIds];
    }

    /**
     * Reported gap: a combo-style BOGO ("buy 1 burger get 1 drink free")
     * only ever discounts a reward unit the cashier already thought to add
     * themselves — computeAutoLineDiscounts() never inserts it, by design
     * (see that method's docblock). This tells the billing popup which
     * reward product(s) the current cart has already earned but doesn't
     * yet contain, so it can prompt the cashier to add it with one tap
     * instead of the promo silently doing nothing.
     *
     * Deliberately skips the self-referential case (reward product ==
     * trigger product, e.g. plain "buy 2 get 1 free" of the same item):
     * there, the "bought" pool and the "reward" pool are the same cart
     * lines, so simply ringing up the item normally already satisfies the
     * promo — computeAutoLineDiscounts() correctly prices one of those
     * units free with no extra line needed. Suggesting an "add more" tap
     * there would also be mathematically unsafe: the suggested unit would
     * itself count toward the buy quantity, which could cascade into
     * suggesting still more. Read-only, no locks — this never runs inside
     * the checkout transaction and never affects stock/money itself, only
     * what's suggested to the client before checkout.
     */
    public static function suggestMissingBogoItems(array $items): array
    {
        $promotions = Promotion::where('type', 'bogo')->where('active', true)->get()
            ->filter(fn ($p) => $p->isWithinDateWindow())
            ->values();

        if ($promotions->isEmpty()) {
            return [];
        }

        $qtyByProduct = [];
        foreach ($items as $item) {
            if (! empty($item['product_unit_id']) || ! empty($item['product_variant_id'])) {
                continue;
            }
            $pid = (int) $item['product_id'];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int) $item['qty'];
        }

        $suggestions = [];
        foreach ($promotions as $promo) {
            $buyQty = $promo->buy_qty ?: 1;
            $rewardProductId = (int) ($promo->get_product_id ?: $promo->buy_product_id);
            $buyProductId = (int) $promo->buy_product_id;
            $qtyBought = $qtyByProduct[$buyProductId] ?? 0;

            if ($qtyBought < $buyQty || $rewardProductId === $buyProductId) {
                continue;
            }

            $times = intdiv($qtyBought, $buyQty);
            $entitled = $times * ($promo->get_qty ?: 1);
            $missing = $entitled - ($qtyByProduct[$rewardProductId] ?? 0);
            if ($missing <= 0) {
                continue;
            }

            $product = Product::find($rewardProductId);
            if (! $product) {
                continue;
            }

            $suggestions[] = [
                'promotion_id' => $promo->id,
                'product_id' => $rewardProductId,
                'product_name' => $product->name,
                'product_emoji' => $product->emoji,
                'missing_qty' => $missing,
            ];
        }

        return $suggestions;
    }

    /**
     * Must be called from inside the checkout transaction — locks the
     * promotion row so two near-simultaneous checkouts against the last
     * remaining redemption of a capped coupon can't both succeed.
     */
    public static function resolveCoupon(?string $code, float $subtotal): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return ['discount' => 0.0, 'promotion' => null];
        }

        $promo = Promotion::where('type', 'coupon')->where('code', $code)->lockForUpdate()->first();

        if (! $promo || ! $promo->active) {
            throw ValidationException::withMessages(['coupon_code' => 'কুপন কোডটি সঠিক নয় বা চালু নেই।']);
        }
        if (! $promo->isWithinDateWindow()) {
            throw ValidationException::withMessages(['coupon_code' => 'কুপনটির মেয়াদ শেষ অথবা এখনো শুরু হয়নি।']);
        }
        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => 'কুপনটির ব্যবহারসীমা শেষ হয়ে গেছে।']);
        }
        if ($promo->min_purchase !== null && $subtotal < (float) $promo->min_purchase) {
            throw ValidationException::withMessages(['coupon_code' => 'সর্বনিম্ন ক্রয় '.number_format((float) $promo->min_purchase, 2).' টাকা না হলে এই কুপন কাজ করবে না।']);
        }

        $discount = $promo->discount_type === 'percent'
            ? round($subtotal * (float) $promo->discount_value / 100, 2)
            : (float) $promo->discount_value;

        return ['discount' => min($discount, $subtotal), 'promotion' => $promo];
    }
}
