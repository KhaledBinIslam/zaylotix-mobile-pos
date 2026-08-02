<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * VAT and service charge are both per-shop on/off toggles (vat_mode='none'
 * and service_charge_rate=null respectively) with a shop-configurable rate
 * — this locks in that the rate is actually read from the shop (not the old
 * hardcoded 15%), and that turning either off actually zeroes it out on a
 * real checkout rather than just hiding it in the UI.
 */
class VatServiceChargeToggleTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_uses_the_shops_own_configured_vat_rate_not_a_hardcoded_15_percent(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['vat_mode' => 'full', 'vat_rate' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 110, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 110]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        // rate=10 backed out of an already-inclusive 110: 110*10/110 = 10.00
        $this->assertEquals(10.0, (float) $sale->vat);
    }

    public function test_checkout_charges_zero_vat_when_shop_has_vat_turned_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['vat_mode' => 'none']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(0.0, (float) $sale->vat);
        $this->assertEquals(100.0, (float) $sale->total);
    }

    public function test_checkout_applies_service_charge_only_when_a_rate_is_set(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['vat_mode' => 'none', 'service_charge_rate' => 5]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 105]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(5.0, (float) $sale->service_charge);
        $this->assertEquals(105.0, (float) $sale->total); // additive on top, unlike VAT
    }

    public function test_checkout_applies_no_service_charge_when_shop_has_it_turned_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['vat_mode' => 'none', 'service_charge_rate' => null]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(0.0, (float) $sale->service_charge);
        $this->assertEquals(100.0, (float) $sale->total);
    }

    public function test_switching_vat_mode_off_and_back_on_preserves_the_shops_previously_saved_rate(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['vat_mode' => 'full', 'vat_rate' => 12]);
        $this->grantFeature($shop, 'vat');

        $this->actingAs($owner, 'web')->patchJson('/app/settings/vat', ['vat_mode' => 'none']);
        $this->assertEquals(12.0, (float) $shop->fresh()->vat_rate); // untouched while off

        $this->actingAs($owner, 'web')->patchJson('/app/settings/vat', ['vat_mode' => 'full']);
        $this->assertEquals(12.0, (float) $shop->fresh()->vat_rate); // restored, not reset to a default

        $this->actingAs($owner, 'web')->patchJson('/app/settings/vat', ['vat_mode' => 'full', 'rate' => 7.5]);
        $this->assertEquals(7.5, (float) $shop->fresh()->vat_rate);
    }
}
