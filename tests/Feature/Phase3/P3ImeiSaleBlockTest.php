<?php

namespace Tests\Feature\Phase3;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\SalesChannel;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * D8 (owner decision 2026-11-28): an IMEI that has been sold cannot be sold again — the checkout is BLOCKED, on the
 * server, for every branch of the business. It becomes sellable again only when it is back in valid stock through a
 * return, an exchange, a void, or an authorised stock correction (re-registering it through stock-in).
 */
class P3ImeiSaleBlockTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private Product $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['serial_tracking', 'online_business']);
        $this->phone = $this->p3Product('Phone', 300, 500, 0);
        $this->p3As()->post("/app/products/{$this->phone->id}/stock-in", ['qty' => 3, 'cost' => 300, 'imeis' => "111\n222\n333"])->assertSessionHasNoErrors();
    }

    private function sellImei(string $imei, array $items = null)
    {
        return $this->p3Checkout($items ?? [['product_id' => $this->phone->id, 'qty' => 1, 'imei' => $imei]], [['method' => 'cash', 'amount' => 500]]);
    }

    private function serialStatus(string $imei, ?int $shop = null): ?string
    {
        return DB::table('product_serials')->where('shop_id', $shop ?? $this->shop->id)->where('imei', $imei)->value('status');
    }

    public function test_a_sold_imei_cannot_be_checked_out_again_and_nothing_changes(): void
    {
        $this->sellImei('111')->assertOk();
        $this->assertSame('sold', $this->serialStatus('111'));
        $before = $this->p3Snap();
        $bills = DB::table('sales')->count();

        $r = $this->sellImei('111');

        $this->assertSame(422, $r->status(), 'checkout of an already sold IMEI is refused: '.substr((string) $r->getContent(), 0, 200));
        $this->assertStringContainsString('111', (string) $r->getContent(), 'the message names the IMEI');
        $this->p3AssertSame($before, $this->p3Snap(), 'no stock, cash or ledger moved');
        $this->assertSame($bills, DB::table('sales')->count(), 'no bill was created');
        $this->assertSame('sold', $this->serialStatus('111'));
    }

    public function test_the_same_imei_twice_in_one_cart_refuses_the_whole_cart(): void
    {
        $before = $this->p3Snap();
        $bills = DB::table('sales')->count();

        $r = $this->p3Checkout([
            ['product_id' => $this->phone->id, 'qty' => 1, 'imei' => '222'],
            ['product_id' => $this->phone->id, 'qty' => 1, 'imei' => '222'],
        ], [['method' => 'cash', 'amount' => 1000]]);

        $this->assertSame(422, $r->status(), substr((string) $r->getContent(), 0, 200));
        $this->p3AssertSame($before, $this->p3Snap());
        $this->assertSame($bills, DB::table('sales')->count());
        $this->assertSame('in_stock', $this->serialStatus('222'), 'the first line was rolled back with the rest');
    }

    public function test_the_imei_is_sellable_again_after_a_resalable_return_and_after_a_void(): void
    {
        $sale = \App\Models\Sale::withoutGlobalScopes()->find($this->sellImei('111')->json('sale.id'));
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']])->assertOk();
        $this->assertSame('in_stock', $this->serialStatus('111'));
        $again = $this->sellImei('111');
        $this->assertSame(200, $again->status(), 'sellable again after a return: '.substr((string) $again->getContent(), 0, 160));

        $second = \App\Models\Sale::withoutGlobalScopes()->find($this->sellImei('222')->json('sale.id'));
        $this->p3Void($second)->assertSessionHas('success');
        $this->assertSame('in_stock', $this->serialStatus('222'));
        $this->assertSame(200, $this->sellImei('222')->status(), 'sellable again after a void');
    }

    public function test_a_damaged_return_does_not_make_the_imei_sellable(): void
    {
        $sale = \App\Models\Sale::withoutGlobalScopes()->find($this->sellImei('333')->json('sale.id'));
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'damaged']])->assertOk();

        $this->assertSame(422, $this->sellImei('333')->status(), 'a written-off unit is not valid stock');
    }

    public function test_a_sold_imei_is_blocked_at_every_branch_of_the_business(): void
    {
        $this->sellImei('111')->assertOk();
        [$branch, $branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $this->shop->id, 'cash_balance' => 10000]);
        $this->grantFeature($branch, 'serial_tracking');
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'Phone', 'cost' => 300, 'price' => 500, 'stock' => 5]);
        $bills = DB::table('sales')->count();

        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $bp->id, 'qty' => 1, 'imei' => '111']], 'payments' => [['method' => 'cash', 'amount' => 500]],
        ]);

        $this->assertSame(422, $r->status(), 'the branch cannot sell an IMEI that was sold at the main shop: '.substr((string) $r->getContent(), 0, 200));
        $this->assertSame($bills, DB::table('sales')->count());
        $this->assertEquals(5.0, (float) DB::table('products')->where('id', $bp->id)->value('stock'), 'branch stock untouched');
    }

    public function test_an_imei_that_sits_in_another_branchs_stock_or_belongs_to_another_product_is_blocked(): void
    {
        [$branch, $branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $this->shop->id, 'cash_balance' => 10000]);
        $this->grantFeature($branch, 'serial_tracking');
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'Phone', 'cost' => 300, 'price' => 500, 'stock' => 5]);
        $other = $this->p3Product('Tablet', 200, 400, 0);
        $this->p3As()->post("/app/products/{$other->id}/stock-in", ['qty' => 1, 'cost' => 200, 'imeis' => 'TAB-1'])->assertSessionHasNoErrors();

        // in stock at the MAIN shop; the branch cannot sell it (it is not on the branch's shelf)
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', ['items' => [['product_id' => $bp->id, 'qty' => 1, 'imei' => '222']], 'payments' => [['method' => 'cash', 'amount' => 500]]]);
        $this->assertSame(422, $r->status(), 'IMEI in another branch\'s stock: '.substr((string) $r->getContent(), 0, 200));

        // a Tablet's IMEI typed against the Phone line
        $wrong = $this->sellImei('TAB-1');
        $this->assertSame(422, $wrong->status(), 'IMEI of a different product: '.substr((string) $wrong->getContent(), 0, 200));
        $this->assertSame('in_stock', $this->serialStatus('TAB-1'));
    }

    public function test_an_imei_that_was_never_registered_still_sells_documented_behaviour(): void
    {
        $r = $this->sellImei('NEVER-REGISTERED');

        $this->assertSame(200, $r->status(), 'unregistered IMEIs never blocked a sale (SerialTrackingTest) — only a KNOWN sold/foreign IMEI is blocked');
    }

    public function test_an_online_order_cannot_sell_a_sold_imei_either(): void
    {
        $this->sellImei('111')->assertOk();
        $channel = SalesChannel::create(['shop_id' => $this->shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $before = $this->p3Snap();
        $orders = DB::table('online_orders')->count();

        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($this->owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111', 'customer_name' => 'Karim',
            'items' => [['product_id' => $this->phone->id, 'qty' => 1, 'imei' => '111']],
        ]);

        $this->assertTrue($r->status() >= 400 || session()->has('errors') || session()->has('error'), 'refused (status '.$r->status().')');
        $this->assertSame($orders, DB::table('online_orders')->count(), 'no order was created');
        $this->p3AssertSame($before, $this->p3Snap());
    }

    // ---- authorised stock correction: re-registering a sold IMEI through stock-in

    public function test_stock_in_of_a_sold_imei_puts_it_back_in_valid_stock_and_it_can_be_sold_again(): void
    {
        $sale = \App\Models\Sale::withoutGlobalScopes()->find($this->sellImei('111')->json('sale.id'));
        $this->assertSame('sold', $this->serialStatus('111'));

        $this->p3As()->post("/app/products/{$this->phone->id}/stock-in", ['qty' => 1, 'cost' => 280, 'imeis' => '111'])->assertSessionHasNoErrors();

        $this->assertSame('in_stock', $this->serialStatus('111'), 'the correction re-registered the unit');
        $this->assertSame(1, ProductSerial::withoutGlobalScopes()->where('imei', '111')->count(), 'one row for one device, never two');
        $this->assertEquals(3.0, (float) DB::table('products')->where('id', $this->phone->id)->value('stock'), '2 left + 1 corrected');
        $this->assertSame(1, DB::table('sale_items')->where('sale_id', $sale->id)->count(), 'the old bill is untouched');
        $this->assertSame(200, $this->sellImei('111')->status(), 'and it can be sold again');
    }

    public function test_stock_in_of_an_imei_already_in_stock_or_repeated_in_the_list_is_refused_cleanly_and_changes_nothing(): void
    {
        $before = $this->p3Snap();
        $serials = DB::table('product_serials')->count();

        $already = $this->p3As()->post("/app/products/{$this->phone->id}/stock-in", ['qty' => 1, 'cost' => 300, 'imeis' => '222']);
        $this->assertLessThan(500, $already->status(), 'IMEI already in stock: clean refusal, status '.$already->status());
        $this->assertTrue(session()->has('errors') && session('errors')->has('imeis'), 'the error is on the imeis field');

        $twice = $this->p3As()->post("/app/products/{$this->phone->id}/stock-in", ['qty' => 2, 'cost' => 300, 'imeis' => "777\n777"]);
        $this->assertLessThan(500, $twice->status(), 'IMEI repeated inside one list: clean refusal, status '.$twice->status());
        $this->assertTrue(session()->has('errors') && session('errors')->has('imeis'));

        $this->p3AssertSame($before, $this->p3Snap(), 'stock and money did not move');
        $this->assertSame($serials, DB::table('product_serials')->count(), 'no serial was created or duplicated');
    }

    public function test_a_new_product_with_a_duplicate_imei_is_refused_before_anything_is_created(): void
    {
        $products = DB::table('products')->count();
        $serials = DB::table('product_serials')->count();

        $r = $this->p3As()->post(route('app.products.store'), [
            'name' => 'Phone 2', 'cost' => 300, 'price' => 500, 'stock' => 2, 'track_serial' => true, 'imeis' => "111\nNEW-1",
        ]);

        $this->assertLessThan(500, $r->status());
        $this->assertTrue(session()->has('errors') && session('errors')->has('imeis'), 'the error names the IMEI list');
        $this->assertSame($products, DB::table('products')->count(), 'the product was not created with a refused IMEI list');
        $this->assertSame($serials, DB::table('product_serials')->count());
    }

    public function test_correcting_a_sold_imei_is_atomic_if_another_imei_in_the_same_list_is_refused(): void
    {
        $this->sellImei('111')->assertOk();
        $before = $this->p3Snap();

        // 111 is sold (would be re-registered) but 222 is already in stock (refused): the whole entry must be refused
        $r = $this->p3As()->post("/app/products/{$this->phone->id}/stock-in", ['qty' => 2, 'cost' => 300, 'imeis' => "111\n222"]);

        $this->assertLessThan(500, $r->status());
        $this->assertSame('sold', $this->serialStatus('111'), '111 was NOT re-registered because the entry as a whole was refused');
        $this->p3AssertSame($before, $this->p3Snap());
    }
}
