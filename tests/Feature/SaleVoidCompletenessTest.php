<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 39 (docs/FIX_PLAN.md) — "বিক্রি + বাতিল = কিছুই হয়নি": every sale
 * here goes through the REAL checkout endpoint (not hand-built rows), gets
 * voided through the real void endpoint, and then the world must look
 * exactly like it did before the sale — including the pieces the original
 * SaleReversal missed (loyalty points, promotion used_count, referral
 * bonus, quotation status) and the collected-due→advance rule.
 */
class SaleVoidCompletenessTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function checkout($owner, array $payload)
    {
        return $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload);
    }

    private function void($owner, Sale $sale): void
    {
        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'completeness test void'])
            ->assertRedirect();
    }

    public function test_loyalty_earn_and_redeem_are_both_reversed_on_void(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_earn_rate' => 2, 'loyalty_point_value' => 2, 'cash_balance' => 1000]);
        $this->grantFeature($shop, 'loyalty_points');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01922222222', 'loyalty_points' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        // redeem 5 points (10tk off) and earn on the 90tk paid
        $this->checkout($owner, [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01922222222',
            'redeem_points' => 5,
            'payments' => [['method' => 'cash', 'amount' => 90]],
        ])->assertOk();

        $sale = Sale::first();
        $this->assertNotSame(10, (int) $customer->fresh()->loyalty_points); // sanity: checkout moved the balance

        $this->void($owner, $sale);

        $customer->refresh();
        $this->assertSame(10, (int) $customer->loyalty_points); // exactly back where it started
        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertEquals(1000, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(0, (float) $customer->total_spent);
        $this->assertSame(0, (int) $customer->visits);
    }

    public function test_coupon_and_bogo_used_counts_are_given_back_on_void(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        $bogo = Promotion::create(['shop_id' => $shop->id, 'name' => 'B2G1', 'type' => 'bogo', 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_product_id' => $product->id, 'get_qty' => 1, 'get_discount_percent' => 100, 'used_count' => 0]);
        $coupon = Promotion::create(['shop_id' => $shop->id, 'name' => 'Flat10', 'type' => 'coupon', 'code' => 'TEN', 'discount_type' => 'flat', 'discount_value' => 10, 'used_count' => 0]);

        $this->checkout($owner, [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'coupon_code' => 'TEN',
            'payments' => [['method' => 'cash', 'amount' => 30]], // 60 - 20 (bogo) - 10 (coupon)
        ])->assertOk();

        $this->assertSame(1, (int) $bogo->fresh()->used_count);
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $sale = Sale::first();
        $this->assertNotNull($sale->promotion_ids);
        $this->assertCount(2, $sale->promotion_ids);

        $this->void($owner, $sale);

        $this->assertSame(0, (int) $bogo->fresh()->used_count);
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertEquals(50, $product->fresh()->stock);
        $this->assertEquals(1000, (float) $shop->fresh()->cash_balance);
    }

    public function test_collected_due_becomes_customer_advance_instead_of_vanishing(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 5]);

        // 200tk sale, 50 tendered, 150 charged to due
        $this->checkout($owner, [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01933333333',
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertOk();

        $sale = Sale::first();
        $customer = Customer::where('phone', '01933333333')->first();
        $this->assertEquals(150, (float) $customer->due);

        // collect 100 of that due, tagged to this invoice
        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", [
            'amount' => 100, 'method' => 'cash', 'sale_id' => $sale->id,
        ])->assertRedirect();
        $this->assertEquals(50, (float) $customer->fresh()->due);
        $this->assertEquals(1150, (float) $shop->fresh()->cash_balance); // 1000 + 50 tender + 100 collection

        $this->void($owner, $sale);

        $customer->refresh();
        $shop->refresh();
        $this->assertEquals(0, (float) $customer->due); // outstanding 50 removed
        $this->assertEquals(100, (float) $customer->advance_balance); // collected 100 became store credit
        $this->assertEquals(1000 + 100, (float) $shop->cash_balance); // tender 50 reversed; collected 100 stays with the shop
        $this->assertEquals(5, $product->fresh()->stock);
    }

    public function test_applying_the_advance_against_a_later_due_is_a_pure_transfer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01944444444', 'due' => 80, 'advance_balance' => 100]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments/apply-advance")
            ->assertRedirect();

        $customer->refresh();
        $this->assertEquals(0, (float) $customer->due);
        $this->assertEquals(20, (float) $customer->advance_balance);
        $this->assertEquals(1000, (float) $shop->fresh()->cash_balance); // no cash moved

        $ledger = Payment::where('customer_id', $customer->id)->first();
        $this->assertSame('advance', $ledger->method);
        $this->assertEquals(80, (float) $ledger->amount);
        $this->assertEquals(80, (float) $ledger->due_before);
        $this->assertEquals(0, (float) $ledger->due_after);
    }

    public function test_referral_bonus_is_clawed_back_when_the_first_sale_is_voided(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50, 'cash_balance' => 1000]);
        $this->grantFeature($shop, 'customer_referrals');
        $this->grantFeature($shop, 'loyalty_points');
        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01955555555', 'loyalty_points' => 0]);
        $referred = Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01966666666', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->checkout($owner, [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01966666666',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $this->assertSame(50, (int) $referrer->fresh()->loyalty_points); // bonus paid
        $this->assertNotNull($referred->fresh()->referral_rewarded_at);

        $this->void($owner, Sale::first());

        $this->assertSame(0, (int) $referrer->fresh()->loyalty_points); // clawed back
        $this->assertNull($referred->fresh()->referral_rewarded_at); // next first sale pays again
    }

    public function test_referral_bonus_stays_when_the_customer_has_another_live_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50, 'cash_balance' => 1000]);
        $this->grantFeature($shop, 'customer_referrals');
        $this->grantFeature($shop, 'loyalty_points');
        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01955555555', 'loyalty_points' => 0]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01966666666', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        foreach ([1, 2] as $i) {
            $this->checkout($owner, [
                'items' => [['product_id' => $product->id, 'qty' => 1]],
                'customer_phone' => '01966666666',
                'payments' => [['method' => 'cash', 'amount' => 100]],
            ])->assertOk();
        }

        $firstSale = Sale::orderBy('id')->first();
        $this->void($owner, $firstSale);

        // the referral genuinely produced a still-live paying customer, so
        // the bonus stands (Phase 39 design note)
        $this->assertSame(50, (int) $referrer->fresh()->loyalty_points);
    }

    public function test_voiding_a_quotation_converted_sale_reopens_the_quotation(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $quotation = Quotation::create([
            'shop_id' => $shop->id, 'quote_no' => 'Q-1001', 'date' => now()->toDateString(),
            'status' => 'open', 'subtotal' => 100, 'discount' => 0, 'total' => 100,
        ]);

        $this->checkout($owner, [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $sale = Sale::first();
        $this->assertSame('converted', $quotation->fresh()->status);
        $this->assertSame($sale->id, $quotation->fresh()->sale_id);

        $this->void($owner, $sale);

        $quotation->refresh();
        $this->assertSame('open', $quotation->status);
        $this->assertNull($quotation->sale_id);
    }
}
