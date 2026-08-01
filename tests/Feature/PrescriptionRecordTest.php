<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * There's no digital way to verify a real prescription — `requires_prescription`
 * on a product and `prescription_note` on the sale are a reminder + record,
 * never a checkout block.
 */
class PrescriptionRecordTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_is_never_blocked_by_a_prescription_required_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa Extra', 'cost' => 1, 'price' => 2, 'stock' => 10, 'requires_prescription' => true]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 4]],
        ]);

        $response->assertOk(); // no note attached at all — still allowed
    }

    public function test_a_prescription_note_is_stored_on_the_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa Extra', 'cost' => 1, 'price' => 2, 'stock' => 10, 'requires_prescription' => true]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 4]],
            'prescription_note' => 'Dr. Rahman — 2x daily for 5 days',
        ])->assertOk();

        $this->assertSame('Dr. Rahman — 2x daily for 5 days', Sale::first()->prescription_note);
    }
}
