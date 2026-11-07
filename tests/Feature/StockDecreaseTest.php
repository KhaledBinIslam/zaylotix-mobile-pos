<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park live: "If someone typed 1 extra piece by mistake, they cannot
 * remove just that 1" — the only existing tool was the ✕ button, which
 * removes the whole variant. decreaseStock()/setExactStock() correct a
 * quantity in place instead, always with a reason, always logged.
 */
class StockDecreaseTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_decrease_stock_with_a_reason_and_it_is_logged(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'wrong_entry',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(9, $v->fresh()->stock);
        $this->assertEquals(9, $p->fresh()->stock);
        $log = ActivityLog::where('subject_type', ProductVariant::class)->where('subject_id', $v->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('product.variant.stockDecrease', $log->action);
        $this->assertEquals(-1, $log->meta['qty']);
        $this->assertSame('wrong_entry', $log->meta['reason']);
    }

    public function test_damaged_and_lost_reasons_write_a_damage_row_but_wrong_entry_does_not(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10, 'cost' => 200]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 2, 'reason' => 'damaged',
        ])->assertSessionHasNoErrors();

        $damage = Damage::where('product_variant_id', $v->id)->first();
        $this->assertNotNull($damage);
        $this->assertEquals(2, $damage->qty);
        $this->assertEquals(400.0, (float) $damage->loss); // 2 * 200

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'wrong_entry',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Damage::where('product_variant_id', $v->id)->count()); // still just the one from 'damaged'
    }

    public function test_other_reason_requires_a_note(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'other',
        ])->assertSessionHasErrors('note');

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'other', 'note' => 'ক্রেতা ফেরত দিয়েছিল, আবার দেওয়া হয়েছে',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(9, $v->fresh()->stock);
    }

    public function test_cannot_decrease_below_zero(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 3]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 3]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 5, 'reason' => 'wrong_entry',
        ])->assertSessionHasErrors('qty');

        $this->assertEquals(3, $v->fresh()->stock);
    }

    public function test_cashier_without_the_permission_is_refused_but_granted_cashier_may(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);

        $cashier = \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role' => 'staff', 'permissions' => ['stock']]);
        $this->actingAs($cashier, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'wrong_entry',
        ])->assertForbidden();

        $cashier->update(['permissions' => ['stock', 'stock_decrease']]);
        $this->actingAs($cashier, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 1, 'reason' => 'wrong_entry',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(9, $v->fresh()->stock);
    }

    public function test_decrease_fifo_drains_source_lines_oldest_first(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['product_variants', 'source_lines', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $sujon = Supplier::create(['name' => 'Sujon']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);
        $old = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $bulu->id, 'cost' => 200, 'price' => 400, 'stock' => 6]);
        $new = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $sujon->id, 'cost' => 200, 'price' => 400, 'stock' => 4]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), [
            'qty' => 8, 'reason' => 'wrong_entry',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $old->fresh()->stock); // oldest drained first
        $this->assertEquals(2, (float) $new->fresh()->stock); // spillover from the second
        $this->assertEquals(2, $v->fresh()->stock);
    }

    public function test_set_exact_stock_both_directions(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);

        // down: reuses the decrease path, no Damage row (pure recount)
        $this->actingAs($owner, 'web')->post(route('app.productVariants.setExactStock', $v->id), ['stock' => 7])
            ->assertSessionHasNoErrors();
        $this->assertEquals(7, $v->fresh()->stock);
        $this->assertEquals(7, $p->fresh()->stock);
        $this->assertSame(0, Damage::where('product_variant_id', $v->id)->count());

        // up: plain top-up, no supplier/line implied
        $this->actingAs($owner, 'web')->post(route('app.productVariants.setExactStock', $v->id), ['stock' => 12])
            ->assertSessionHasNoErrors();
        $this->assertEquals(12, $v->fresh()->stock);
        $this->assertEquals(12, $p->fresh()->stock);
    }

    public function test_set_exact_stock_down_also_requires_the_permission_for_a_cashier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);
        $cashier = \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role' => 'staff', 'permissions' => ['stock']]);

        $this->actingAs($cashier, 'web')->post(route('app.productVariants.setExactStock', $v->id), ['stock' => 5])
            ->assertForbidden();
        // an UPWARD correction needs no special permission — same as a plain stock-in
        $this->actingAs($cashier, 'web')->post(route('app.productVariants.setExactStock', $v->id), ['stock' => 15])
            ->assertSessionHasNoErrors();
        $this->assertEquals(15, $v->fresh()->stock);
    }

    public function test_history_endpoint_lists_newest_first(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), ['qty' => 1, 'reason' => 'wrong_entry']);
        $this->actingAs($owner, 'web')->post(route('app.productVariants.decreaseStock', $v->id), ['qty' => 1, 'reason' => 'lost']);

        $res = $this->actingAs($owner, 'web')->get(route('app.productVariants.history', $v->id))->assertOk();
        $rows = $res->json('rows');
        $this->assertCount(2, $rows);
        $this->assertSame('product.variant.stockDecrease', $rows[0]['action']); // newest first
        $this->assertEquals(-1, $rows[0]['meta']['qty']);
    }
}
