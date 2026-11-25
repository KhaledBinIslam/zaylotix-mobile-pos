<?php

namespace Tests\Feature;

use App\Http\Controllers\App\AccountsController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\HeldCart;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SalesChannel;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Does the data REALLY update?" for every SALES feature — each flow driven
 * through its real HTTP route on a Baby-Park-shaped two-branch pool (main +
 * one branch, shared_catalog + shared_stock_pool, variants, returns, online
 * business), with EVERY layer asserted afterwards: variant + product stock in
 * BOTH branches, the real stockValue() on both, cash/bank balance, customer
 * due/points/total_spent, sales + sale_items + sale_payments rows, profit
 * figures, activity log and the Reports numbers.
 *
 * Fixture (all born at the MAIN shop through the real creation hooks, so the
 * branch copies are real mirrors):
 *   SHOE  product cost 500 / price 1000, stock 20
 *     size 40 — own cost 600, stock 10
 *     size 41 — cost NULL (falls back to the product's 500), stock 10
 *   POLISH plain product cost 50 / price 80 / wholesale 60, stock 20
 * Pool stock value at the start: 10*600 + 10*500 + 20*50 = 12000.
 */
class E2ESalesFlowsTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $main;

    private Shop $branch;

    private User $mainOwner;

    private User $branchOwner;

    private Product $shop;      // SHOE at main

    private Product $shopB;     // SHOE at branch (mirror)

    private ProductVariant $v40;

    private ProductVariant $v40B;

    private ProductVariant $v41;

    private ProductVariant $v41B;

    private Product $polish;

    private Product $polishB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->main, $this->mainOwner] = $this->createShopWithOwner(['name' => 'Baby Park', 'cash_balance' => 10000]);
        [$this->branch, $this->branchOwner] = $this->createShopWithOwner(['name' => 'Liberty', 'parent_shop_id' => $this->main->id, 'cash_balance' => 5000]);
        foreach ([$this->main, $this->branch] as $s) {
            foreach (['product_variants', 'suppliers', 'returns', 'online_business', 'accounts', 'damages'] as $f) {
                $this->grantFeature($s, $f);
            }
        }
        $this->grantFeature($this->main, 'shared_catalog');
        $this->grantFeature($this->main, 'shared_stock_pool');

        Tenancy::set($this->main->id);
        $this->shop = Product::create(['shop_id' => $this->main->id, 'name' => 'SHOE', 'barcode' => 'SHOE-1', 'cost' => 500, 'price' => 1000, 'stock' => 20]);
        $this->v40 = ProductVariant::create(['shop_id' => $this->main->id, 'product_id' => $this->shop->id, 'size' => '40', 'color' => 'BLACK', 'barcode' => 'SHOE-1-BLK-40', 'stock' => 10, 'cost' => 600]);
        $this->v41 = ProductVariant::create(['shop_id' => $this->main->id, 'product_id' => $this->shop->id, 'size' => '41', 'color' => 'BLACK', 'barcode' => 'SHOE-1-BLK-41', 'stock' => 10, 'cost' => null]);
        $this->polish = Product::create(['shop_id' => $this->main->id, 'name' => 'POLISH', 'barcode' => 'POLISH-1', 'cost' => 50, 'price' => 80, 'wholesale_price' => 60, 'stock' => 20]);

        $this->shopB = $this->mirror($this->shop);
        $this->polishB = $this->mirror($this->polish);
        $this->v40B = ProductVariant::withoutGlobalScopes()->where('product_id', $this->shopB->id)->where('size', '40')->firstOrFail();
        $this->v41B = ProductVariant::withoutGlobalScopes()->where('product_id', $this->shopB->id)->where('size', '41')->firstOrFail();

        Tenancy::clear();
        $this->assertPool(10, 10, 20, 'fixture: the creation hooks mirrored the pool at the same figures');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function mirror(Product $p): Product
    {
        return Product::withoutGlobalScopes()->where('shop_id', $this->branch->id)->where('master_product_id', $p->id)->firstOrFail();
    }

    private function stockValue(Shop $shop): float
    {
        $m = new \ReflectionMethod(AccountsController::class, 'stockValue');
        $m->setAccessible(true);
        Tenancy::forgetShopCache();
        Tenancy::set($shop->id);
        try {
            return (float) $m->invoke(new AccountsController);
        } finally {
            Tenancy::clear();
        }
    }

    /** Variant + product stock in BOTH branches, and the real stock value on both, in one go. */
    private function assertPool(float $s40, float $s41, float $polish, string $why = ''): void
    {
        foreach (['main' => [$this->v40, $this->v41, $this->shop, $this->polish], 'branch' => [$this->v40B, $this->v41B, $this->shopB, $this->polishB]] as $side => [$a, $b, $shoe, $pol]) {
            $this->assertEquals($s40, (float) ProductVariant::withoutGlobalScopes()->find($a->id)->stock, "{$side} variant 40 stock — {$why}");
            $this->assertEquals($s41, (float) ProductVariant::withoutGlobalScopes()->find($b->id)->stock, "{$side} variant 41 stock — {$why}");
            $this->assertEquals($s40 + $s41, (float) Product::withoutGlobalScopes()->find($shoe->id)->stock, "{$side} SHOE product stock = sum of variants — {$why}");
            $this->assertEquals($polish, (float) Product::withoutGlobalScopes()->find($pol->id)->stock, "{$side} POLISH stock — {$why}");
        }
        $expected = $s40 * 600 + $s41 * 500 + $polish * 50;
        $this->assertEqualsWithDelta($expected, $this->stockValue($this->main), 0.01, "main stockValue — {$why}");
        $this->assertEqualsWithDelta($expected, $this->stockValue($this->branch), 0.01, "branch stockValue — {$why}");
    }

    private function money(Shop $s): array
    {
        $f = Shop::withoutGlobalScopes()->find($s->id);

        return [(float) $f->cash_balance, (float) $f->bank_balance];
    }

    private function assertMoney(Shop $s, float $cash, float $bank, string $why = ''): void
    {
        [$c, $b] = $this->money($s);
        $this->assertEqualsWithDelta($cash, $c, 0.01, "{$s->name} cash — {$why}");
        $this->assertEqualsWithDelta($bank, $b, 0.01, "{$s->name} bank — {$why}");
    }

    private function cust(Shop $s, array $attrs = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge(['shop_id' => $s->id, 'name' => 'Rina', 'phone' => '018'.random_int(10000000, 99999999), 'due' => 0, 'total_spent' => 0, 'visits' => 0, 'loyalty_points' => 0], $attrs));
    }

    private function cfresh(Customer $c): Customer
    {
        return Customer::withoutGlobalScopes()->find($c->id);
    }

    private function sale(int $id): Sale
    {
        return Sale::withoutGlobalScopes()->findOrFail($id);
    }

    private function stats(Shop $s): array
    {
        Tenancy::forgetShopCache();
        Tenancy::set($s->id);
        $d = now()->toDateString();
        try {
            return Reports::rangeStats($d, $d);
        } finally {
            Tenancy::clear();
        }
    }

    private function logs(string $action): int
    {
        return ActivityLog::withoutGlobalScopes()->where('action', $action)->count();
    }

    private function as(User $u, ?Shop $switchedInto = null)
    {
        Tenancy::clear(); // resolve from the user + session like a real request, never from a leftover explicit set()
        Tenancy::forgetShopCache();
        $this->flushSession(); // withSession() sticks to the test session — an un-switched call must really be un-switched
        $t = $this->withSession($switchedInto ? ['active_branch_id' => $switchedInto->id] : []);

        return $t->actingAs($u, 'web');
    }

    /** a POS cart line; $at = the shop whose product/variant ids are used */
    private function line(string $what, string $at, float $qty = 1, float $discount = 0): array
    {
        $b = $at === 'branch';
        [$p, $v] = match ($what) {
            '40' => [$b ? $this->shopB : $this->shop, $b ? $this->v40B : $this->v40],
            '41' => [$b ? $this->shopB : $this->shop, $b ? $this->v41B : $this->v41],
            'polish' => [$b ? $this->polishB : $this->polish, null],
        };

        return array_filter(['product_id' => $p->id, 'product_variant_id' => $v?->id, 'qty' => $qty, 'discount' => $discount ?: null], fn ($x) => $x !== null);
    }

    private function checkout(User $u, array $payload, ?Shop $switchedInto = null)
    {
        return $this->as($u, $switchedInto)->postJson('/app/pos/checkout', $payload);
    }

    /** rings a sale through the real route, returns its Sale */
    private function ring(string $at, array $items, array $payments, array $extra = []): Sale
    {
        $u = $at === 'branch' ? $this->branchOwner : $this->mainOwner;
        $r = $this->checkout($u, array_merge(['items' => $items, 'payments' => $payments], $extra));
        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 400));

        return $this->sale($r->json('sale.id'));
    }

    private function void(User $u, Sale $s, string $reason = 'customer cancelled', ?Shop $switchedInto = null)
    {
        return $this->as($u, $switchedInto)->delete("/app/sales/{$s->id}", ['reason' => $reason]);
    }

    private function process(User $u, array $payload, ?Shop $switchedInto = null)
    {
        return $this->as($u, $switchedInto)->postJson('/app/returns/process', $payload);
    }

    // ==================================================================
    // 1. POS CHECKOUT
    // ==================================================================

    public function test_checkout_cash_variant_at_main_updates_every_layer(): void
    {
        $sale = $this->ring('main', [$this->line('40', 'main', 2)], [['method' => 'cash', 'amount' => 2000]]);

        $this->assertSame($this->main->id, (int) $sale->shop_id);
        $this->assertEquals(2000, (float) $sale->total);
        $this->assertEquals(800, (float) $sale->profit, '(1000-600)*2 — the variant\'s OWN cost');
        $this->assertSame('cash', $sale->payment_mode);

        $items = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
        $this->assertCount(1, $items);
        $this->assertSame($this->v40->id, (int) $items[0]->product_variant_id);
        $this->assertSame($this->main->id, (int) $items[0]->shop_id);
        $this->assertEquals(600, (float) $items[0]->cost);
        $this->assertEquals(2, (float) $items[0]->qty);

        $pay = SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
        $this->assertCount(1, $pay);
        $this->assertSame('cash', $pay[0]->method);
        $this->assertEquals(2000, (float) $pay[0]->amount);
        $this->assertSame($this->main->id, (int) $pay[0]->shop_id);

        $this->assertMoney($this->main, 12000, 0, 'cash in the till');
        $this->assertMoney($this->branch, 5000, 0, 'the sibling branch till is untouched');
        $this->assertPool(8, 10, 20, 'sold at main, pool-wide');

        $m = $this->stats($this->main);
        $this->assertSame(1, $m['count']);
        $this->assertEquals(2000, $m['grossSales']);
        $this->assertEquals(800, $m['grossProfit']);
        $this->assertSame(0, $this->stats($this->branch)['count'], 'the branch report does not see main\'s bill');
    }

    public function test_checkout_bank_variant_with_null_cost_at_branch_and_loyalty(): void
    {
        $this->grantFeature($this->branch, 'loyalty_points');
        Shop::whereKey($this->branch->id)->update(['loyalty_earn_rate' => 5]);
        $c = $this->cust($this->branch);

        $sale = $this->ring('branch', [$this->line('41', 'branch', 1)], [['method' => 'bkash', 'amount' => 1000]], ['customer_id' => $c->id]);

        $this->assertSame($this->branch->id, (int) $sale->shop_id);
        $this->assertEquals(500, (float) $sale->profit, 'variant cost NULL -> falls back to the product cost 500 (1000-500)');
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertEquals(500, (float) $item->cost);
        $this->assertSame($this->v41B->id, (int) $item->product_variant_id);

        $p = SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertSame('bkash', $p->method);
        $this->assertSame($this->branch->id, (int) $p->shop_id);

        $this->assertMoney($this->branch, 5000, 1000, 'bkash goes to the bank bucket, never the till');
        $this->assertMoney($this->main, 10000, 0, 'main untouched');
        $this->assertPool(10, 9, 20, 'sold at the branch, pool-wide');

        $cf = $this->cfresh($c);
        $this->assertEquals(0, (float) $cf->due);
        $this->assertEquals(1000, (float) $cf->total_spent);
        $this->assertSame(1, (int) $cf->visits);
        $this->assertSame(50, (int) $cf->loyalty_points, '1000 x 5 per 100 = 50 points');
        $this->assertSame(50, (int) $sale->points_earned);

        $b = $this->stats($this->branch);
        $this->assertEquals(1000, $b['grossSales']);
        $this->assertEquals(500, $b['grossProfit']);
    }

    public function test_checkout_plain_product_partial_payment_leaves_the_rest_on_the_customers_due(): void
    {
        $c = $this->cust($this->branch);
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 4)], [['method' => 'cash', 'amount' => 100]], ['customer_id' => $c->id]);

        $this->assertEquals(320, (float) $sale->total);
        $this->assertEquals(120, (float) $sale->profit, '(80-50)*4');
        $this->assertEquals(100, (float) SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->sum('amount'), 'only what was really handed over is a payment');
        $this->assertSame(1, SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->count());

        $cf = $this->cfresh($c);
        $this->assertEquals(220, (float) $cf->due, '320 - 100 paid');
        $this->assertEquals(320, (float) $cf->total_spent);
        $this->assertSame(1, (int) $cf->visits);

        $this->assertMoney($this->branch, 5100, 0, 'only the 100 in hand');
        $this->assertPool(10, 10, 16, 'plain product, pool-wide');
    }

    public function test_checkout_fully_on_due_moves_no_money(): void
    {
        $c = $this->cust($this->main);
        $sale = $this->ring('main', [$this->line('polish', 'main', 1)], [], ['customer_id' => $c->id]);

        $this->assertEquals(80, (float) $sale->total);
        $this->assertSame(0, SalePayment::withoutGlobalScopes()->where('sale_id', $sale->id)->count());
        $this->assertEquals(80, (float) $this->cfresh($c)->due);
        $this->assertEquals(80, (float) $this->cfresh($c)->total_spent);
        $this->assertMoney($this->main, 10000, 0, 'a credit sale puts nothing in the till');
        $this->assertPool(10, 10, 19);
    }

    public function test_checkout_with_item_discount_and_bill_discount_keeps_profit_honest(): void
    {
        $sale = $this->ring('main', [$this->line('40', 'main', 2, 100)], [['method' => 'cash', 'amount' => 1850]], ['discount' => 50]);

        $this->assertEquals(1850, (float) $sale->total, '2000 - 100 line - 50 bill');
        $this->assertEquals(50, (float) $sale->discount);
        $this->assertEquals(650, (float) $sale->profit, '(1000-600)*2 - 100 - 50');
        $this->assertEquals(100, (float) \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first()->discount);
        $this->assertMoney($this->main, 11850, 0);
        $this->assertPool(8, 10, 20);
        $this->assertEquals(650, $this->stats($this->main)['grossProfit']);
    }

    public function test_checkout_vat_backed_out_and_service_charge_added_on_top(): void
    {
        Shop::whereKey($this->branch->id)->update(['vat_mode' => 'full', 'vat_rate' => 15, 'service_charge_rate' => 10]);
        // 5 x 80 = 400, +10% service = 440; VAT 15% is inside the price (on the 400)
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 5)], [['method' => 'cash', 'amount' => 440]]);

        $this->assertEquals(440, (float) $sale->total);
        $this->assertEqualsWithDelta(52.17, (float) $sale->vat, 0.01, '400 x 15/115');
        $this->assertEquals(40, (float) $sale->service_charge);
        $this->assertMoney($this->branch, 5440, 0);
        $this->assertPool(10, 10, 15);
        $this->assertEqualsWithDelta(52.17, $this->stats($this->branch)['vat'], 0.01, 'the Reports VAT line carries the stored VAT');
    }

    public function test_checkout_wholesale_uses_the_wholesale_price(): void
    {
        $this->grantFeature($this->main, 'wholesale_pricing');
        $sale = $this->ring('main', [$this->line('polish', 'main', 10)], [['method' => 'cash', 'amount' => 600]], ['sale_type' => 'wholesale']);

        $this->assertEquals(600, (float) $sale->total, '10 x wholesale 60, not retail 80');
        $this->assertSame('wholesale', $sale->sale_type);
        $this->assertEquals(100, (float) $sale->profit, '(60-50)*10');
        $this->assertMoney($this->main, 10600, 0);
        $this->assertPool(10, 10, 10);
    }

    public function test_checkout_by_the_owner_switched_into_the_branch_is_booked_at_the_branch(): void
    {
        $r = $this->checkout($this->mainOwner, ['items' => [$this->line('41', 'branch', 1)], 'payments' => [['method' => 'cash', 'amount' => 1000]]], $this->branch);
        $r->assertOk();
        $sale = $this->sale($r->json('sale.id'));

        $this->assertSame($this->branch->id, (int) $sale->shop_id, 'booked on the branch the owner is working in');
        $this->assertMoney($this->branch, 6000, 0);
        $this->assertMoney($this->main, 10000, 0, 'not on the owner\'s home shop');
        $this->assertPool(10, 9, 20);
    }

    public function test_checkout_is_idempotent_on_the_same_client_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $payload = ['client_uuid' => $uuid, 'items' => [$this->line('40', 'main', 1)], 'payments' => [['method' => 'cash', 'amount' => 1000]]];

        $a = $this->checkout($this->mainOwner, $payload)->assertOk();
        $b = $this->checkout($this->mainOwner, $payload)->assertOk();

        $this->assertSame($a->json('sale.id'), $b->json('sale.id'), 'the resend gets the SAME bill back');
        $this->assertSame(1, Sale::withoutGlobalScopes()->where('client_uuid', $uuid)->count());
        $this->assertSame(1, SalePayment::withoutGlobalScopes()->where('sale_id', $a->json('sale.id'))->count());
        $this->assertMoney($this->main, 11000, 0, 'cash counted once');
        $this->assertPool(9, 10, 20, 'stock taken once');
    }

    // ==================================================================
    // 2. HELD CART
    // ==================================================================

    private function heldPayload(string $label, string $at = 'main'): array
    {
        return ['label' => $label, 'cart' => [array_merge($this->line('40', $at, 1), ['name' => 'SHOE'])], 'discount' => 0, 'customer_phone' => '', 'customer_name' => ''];
    }

    public function test_held_cart_store_list_resume_and_checkout_after_resume(): void
    {
        $this->as($this->mainOwner)->post(route('app.heldCarts.store'), $this->heldPayload('Table 1'))->assertRedirect();
        $held = HeldCart::withoutGlobalScopes()->where('label', 'Table 1')->firstOrFail();
        $this->assertSame($this->main->id, (int) $held->shop_id);

        // listed on the POS page
        $this->as($this->mainOwner)->get(route('app.pos'))->assertInertia(fn ($p) => $p->where('heldCarts', fn ($carts) => collect($carts)->pluck('id')->contains($held->id)));

        // resume hands the cart back and removes the hold
        $r = $this->as($this->mainOwner)->post(route('app.heldCarts.resume', $held->id))->assertOk();
        $this->assertSame($this->v40->id, (int) $r->json('cart.0.product_variant_id'));
        $this->assertNull(HeldCart::withoutGlobalScopes()->find($held->id), 'resumed -> no longer held');

        // …and the resumed cart checks out for real
        $sale = $this->ring('main', [$this->line('40', 'main', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $this->assertEquals(1000, (float) $sale->total);
        $this->assertMoney($this->main, 11000, 0);
        $this->assertPool(9, 10, 20);
        $this->assertSame(0, HeldCart::withoutGlobalScopes()->count());
    }

    public function test_held_cart_delete_as_owner_changes_no_stock_or_money(): void
    {
        $this->as($this->mainOwner)->post(route('app.heldCarts.store'), $this->heldPayload('Gone'))->assertRedirect();
        $held = HeldCart::withoutGlobalScopes()->firstOrFail();

        $this->as($this->mainOwner)->delete(route('app.heldCarts.destroy', $held->id))->assertRedirect()->assertSessionHas('success');
        $this->assertNull(HeldCart::withoutGlobalScopes()->find($held->id));
        $this->assertPool(10, 10, 20, 'a hold never reserves or releases stock');
        $this->assertMoney($this->main, 10000, 0);
    }

    public function test_held_cart_delete_works_for_the_owner_switched_into_the_branch(): void
    {
        // held while working in the branch -> belongs to the branch…
        $this->as($this->mainOwner, $this->branch)->post(route('app.heldCarts.store'), $this->heldPayload('At branch', 'branch'))->assertRedirect();
        $held = HeldCart::withoutGlobalScopes()->where('label', 'At branch')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $held->shop_id);

        // …listed on the branch POS page…
        $this->as($this->mainOwner, $this->branch)->get(route('app.pos'))->assertInertia(fn ($p) => $p->where('heldCarts', fn ($c) => collect($c)->pluck('id')->contains($held->id)));
        // …and NOT on the main one
        $this->as($this->mainOwner)->get(route('app.pos'))->assertInertia(fn ($p) => $p->where('heldCarts', fn ($c) => ! collect($c)->pluck('id')->contains($held->id)));

        // delete as the switched owner works
        $this->as($this->mainOwner, $this->branch)->delete(route('app.heldCarts.destroy', $held->id))->assertRedirect()->assertSessionHas('success');
        $this->assertNull(HeldCart::withoutGlobalScopes()->find($held->id));
    }

    public function test_held_cart_made_at_the_branch_by_its_own_owner_can_be_deleted_by_the_switched_main_owner_and_resumed(): void
    {
        $this->as($this->branchOwner)->post(route('app.heldCarts.store'), $this->heldPayload('Branch own', 'branch'))->assertRedirect();
        $held = HeldCart::withoutGlobalScopes()->where('label', 'Branch own')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $held->shop_id);

        // the main owner NOT switched cannot touch it
        $this->assertContains($this->as($this->mainOwner)->delete(route('app.heldCarts.destroy', $held->id))->status(), [403, 404], 'another branch\x27s hold is out of reach unless switched in');
        $this->assertNotNull(HeldCart::withoutGlobalScopes()->find($held->id), 'still there');

        // switched into the branch they can resume it (and it checks out at the branch)
        $r = $this->as($this->mainOwner, $this->branch)->post(route('app.heldCarts.resume', $held->id))->assertOk();
        $this->assertSame($this->v40B->id, (int) $r->json('cart.0.product_variant_id'));
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $this->assertSame($this->branch->id, (int) $sale->shop_id);
        $this->assertPool(9, 10, 20);
    }

    // ==================================================================
    // 3. VOID
    // ==================================================================

    public function test_void_cash_sale_reverses_everything_exactly_once(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 2), $this->line('polish', 'branch', 3)], [['method' => 'cash', 'amount' => 2240]]);
        $this->assertMoney($this->branch, 7240, 0);
        $this->assertPool(8, 10, 17);

        $this->void($this->branchOwner, $sale)->assertRedirect()->assertSessionHas('success');

        $this->assertMoney($this->branch, 5000, 0, 'cash fully back out of the till');
        $this->assertMoney($this->main, 10000, 0);
        $this->assertPool(10, 10, 20, 'stock back in BOTH branches, stock value restored');
        $this->assertNotNull($this->sale($sale->id)->voided_at);
        $this->assertSame('customer cancelled', $this->sale($sale->id)->voided_reason);
        $this->assertSame(1, $this->logs('sale.void'));
        $log = ActivityLog::withoutGlobalScopes()->where('action', 'sale.void')->first();
        $this->assertSame($this->branch->id, (int) $log->shop_id);
        $this->assertSame(0, $this->stats($this->branch)['count'], 'a voided bill drops out of the reports');
        $this->assertEquals(0, $this->stats($this->branch)['grossSales']);

        // second void: rejected with a message, nothing moves a second time
        $this->void($this->branchOwner, $sale)->assertRedirect()->assertSessionHas('error');
        $this->assertMoney($this->branch, 5000, 0, 'not reversed twice');
        $this->assertPool(10, 10, 20, 'not restocked twice');
        $this->assertSame(1, $this->logs('sale.void'));
    }

    public function test_void_a_bank_sale_with_loyalty_reverses_the_customer_too(): void
    {
        $this->grantFeature($this->branch, 'loyalty_points');
        Shop::whereKey($this->branch->id)->update(['loyalty_earn_rate' => 5]);
        $c = $this->cust($this->branch);
        $sale = $this->ring('branch', [$this->line('41', 'branch', 1)], [['method' => 'nagad', 'amount' => 1000]], ['customer_id' => $c->id]);
        $this->assertSame(50, (int) $this->cfresh($c)->loyalty_points);

        $this->void($this->branchOwner, $sale)->assertSessionHas('success');

        $cf = $this->cfresh($c);
        $this->assertSame(0, (int) $cf->loyalty_points, 'earned points taken back');
        $this->assertEquals(0, (float) $cf->total_spent);
        $this->assertSame(0, (int) $cf->visits);
        $this->assertMoney($this->branch, 5000, 0, 'bank bucket reversed');
        $this->assertPool(10, 10, 20);
    }

    public function test_void_a_fully_due_sale_clears_the_customers_due(): void
    {
        $c = $this->cust($this->branch);
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 5)], [], ['customer_id' => $c->id]);
        $this->assertEquals(400, (float) $this->cfresh($c)->due);

        $this->void($this->branchOwner, $sale)->assertSessionHas('success');

        $this->assertEquals(0, (float) $this->cfresh($c)->due);
        $this->assertEquals(0, (float) $this->cfresh($c)->total_spent);
        $this->assertMoney($this->branch, 5000, 0, 'no money was ever taken, none is given back');
        $this->assertPool(10, 10, 20);
    }

    public function test_void_a_partially_paid_sale_returns_exactly_the_cash_taken_and_clears_the_rest_of_the_due(): void
    {
        $c = $this->cust($this->branch);
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 4)], [['method' => 'cash', 'amount' => 100]], ['customer_id' => $c->id]);
        $this->assertEquals(220, (float) $this->cfresh($c)->due);

        $this->void($this->branchOwner, $sale)->assertSessionHas('success');

        $this->assertEquals(0, (float) $this->cfresh($c)->due, 'the 220 on the books is wiped');
        $this->assertEquals(0, (float) $this->cfresh($c)->total_spent);
        $this->assertMoney($this->branch, 5000, 0, 'the 100 handed over goes back, no more no less');
        $this->assertPool(10, 10, 20);
    }

    public function test_void_by_the_switched_owner_works_and_a_main_owner_not_switched_cannot_void_a_branch_bill(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);

        $this->assertContains($this->void($this->mainOwner, $sale)->status(), [403, 404], 'a branch bill is out of reach unless switched in');
        $this->assertNull($this->sale($sale->id)->voided_at);
        $this->assertPool(9, 10, 20);

        $this->void($this->mainOwner, $sale, 'void by owner at branch', $this->branch)->assertSessionHas('success');
        $this->assertNotNull($this->sale($sale->id)->voided_at);
        $this->assertMoney($this->branch, 5000, 0);
        $this->assertMoney($this->main, 10000, 0);
        $this->assertPool(10, 10, 20);
    }

    // ==================================================================
    // 4. RETURN / EXCHANGE
    // ==================================================================

    public function test_return_resalable_refund_in_cash_restocks_both_branches_and_writes_every_ledger(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 2)], [['method' => 'cash', 'amount' => 2000]]);
        $item = $sale->items()->withoutGlobalScopes()->first() ?? \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertPool(8, 10, 20);

        $this->process($this->branchOwner, ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'wrong size', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]])->assertOk();

        $this->assertPool(9, 10, 20, 'one pair back on the shelf, pool-wide');
        $this->assertMoney($this->branch, 6000, 0, '5000 + 2000 sold - 1000 refunded');
        $ret = SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
        $this->assertCount(1, $ret);
        $this->assertEquals(1, (float) $ret[0]->qty);
        $this->assertEquals(1000, (float) $ret[0]->refund);
        $this->assertSame($this->branch->id, (int) $ret[0]->shop_id);
        $this->assertSame($this->v40B->id, (int) $ret[0]->product_variant_id);
        $this->assertEquals(600, (float) $ret[0]->cost, 'the variant\'s own cost is what is recovered');
        $this->assertSame(1, $this->logs('return.process'), 'one return activity row');

        $st = $this->stats($this->branch);
        $this->assertEquals(1000, $st['returnsTotal']);
        $this->assertEquals(1000, $st['salesAmt'], '2000 gross - 1000 handed back');
        $this->assertEquals(400, $st['grossProfit'], '800 profit - the returned pair\'s 400');
    }

    public function test_return_damaged_never_restocks_writes_off_the_cost_and_still_refunds(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $this->process($this->branchOwner, ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'torn', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'damaged']]])->assertOk();

        $this->assertPool(9, 10, 20, 'damaged goods are NOT sellable stock');
        $this->assertMoney($this->branch, 5000, 0, '+1000 sale, -1000 refund');
        $d = Damage::withoutGlobalScopes()->first();
        $this->assertNotNull($d);
        $this->assertEquals(600, (float) $d->loss);
        $this->assertSame($this->branch->id, (int) $d->shop_id);
        $this->assertSame($this->v40B->id, (int) $d->product_variant_id);
        $this->assertEquals(1, (float) $d->qty);
        $this->assertEquals(600, $this->stats($this->branch)['dmg'], 'the write-off shows in reports');
        $this->assertSame(1, $this->logs('return.process'));
    }

    public function test_return_against_a_due_bill_reduces_the_due_before_paying_cash(): void
    {
        $c = $this->cust($this->branch);
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 5)], [['method' => 'cash', 'amount' => 100]], ['customer_id' => $c->id]);
        $this->assertEquals(300, (float) $this->cfresh($c)->due);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $this->process($this->branchOwner, ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'unused', 'lines' => [['sale_item_id' => $item->id, 'qty' => 3, 'condition' => 'resalable']]])->assertOk();

        $this->assertPool(10, 10, 18, '5 sold, 3 back');
        $refund = (float) SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->sum('refund');
        $this->assertEquals(240, $refund);
        $dueDrop = 300 - (float) $this->cfresh($c)->due;
        [$cash] = $this->money($this->branch);
        $cashOut = 5100 - $cash;
        $this->assertGreaterThan(0, $dueDrop, 'the refund first wipes what the customer still owes');
        $this->assertEqualsWithDelta(240, $dueDrop + $cashOut, 0.01, 'every taka of the refund went to due or to cash — none lost, none doubled');
        $this->assertGreaterThanOrEqual(0, (float) $this->cfresh($c)->due);
    }

    public function test_exchange_for_a_dearer_item_collects_the_difference_and_books_a_replacement_sale(): void
    {
        $sale = $this->ring('branch', [$this->line('polish', 'branch', 1)], [['method' => 'cash', 'amount' => 80]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertMoney($this->branch, 5080, 0);

        $this->process($this->branchOwner, [
            'sale_id' => $sale->id, 'action' => 'exchange', 'reason' => 'wanted shoes',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [$this->line('41', 'branch', 1)],
            'payments' => [['method' => 'cash', 'amount' => 920]],
        ])->assertOk();

        $this->assertPool(10, 9, 20, 'polish back, a size-41 shoe out — pool-wide');
        $this->assertMoney($this->branch, 6000, 0, '5080 + 920 difference');
        $ex = Sale::withoutGlobalScopes()->where('sale_type', 'exchange')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $ex->shop_id);
        $this->assertEquals(1000, (float) $ex->total);
        $this->assertEquals(500, (float) $ex->profit, 'the NEW item\'s own profit: variant cost NULL -> product cost 500');
        $this->assertEquals(920, (float) SalePayment::withoutGlobalScopes()->where('sale_id', $ex->id)->where('method', 'cash')->sum('amount'));
        $this->assertEquals(80, (float) SalePayment::withoutGlobalScopes()->where('sale_id', $ex->id)->where('method', 'trade_credit')->sum('amount'), 'the traded-in 80 is its own tender line');
        $ret = SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame($ex->id, (int) $ret->exchange_sale_id);
        $this->assertSame(1, $this->logs('return.exchange'));

        $st = $this->stats($this->branch);
        $this->assertSame(1, $st['count'], 'an exchange\'s replacement bill is not a second bill');
        $this->assertEquals(1000 + 80 - 80, $st['salesAmt'], 'gross 80 + 1000, minus the 80 given back');
    }

    public function test_exchange_for_a_cheaper_item_refunds_the_difference(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $this->process($this->branchOwner, [
            'sale_id' => $sale->id, 'action' => 'exchange', 'reason' => 'too dear',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [$this->line('polish', 'branch', 2)],
        ])->assertOk();

        $this->assertPool(10, 10, 18, 'shoe back, two polishes out');
        $this->assertMoney($this->branch, 5160, 0, '5000 + 1000 sold - 840 handed back (1000 - 160)');
        $ex = Sale::withoutGlobalScopes()->where('sale_type', 'exchange')->firstOrFail();
        $this->assertEquals(160, (float) $ex->total);
        $this->assertEquals(60, (float) $ex->profit, '(80-50)*2');
    }

    public function test_void_after_an_exchange_is_blocked_and_changes_nothing(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->process($this->branchOwner, [
            'sale_id' => $sale->id, 'action' => 'exchange', 'reason' => 'swap',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [$this->line('polish', 'branch', 2)],
        ])->assertOk();
        [$cash] = $this->money($this->branch);

        $this->void($this->branchOwner, $sale)->assertRedirect()->assertSessionHas('error');

        $this->assertNull($this->sale($sale->id)->voided_at, 'the exchanged bill stays');
        $this->assertPool(10, 10, 18, 'no second restock');
        $this->assertMoney($this->branch, $cash, 0, 'no second money movement');
        $this->assertSame(0, $this->logs('sale.void'));
    }

    public function test_a_voided_bill_cannot_be_returned_or_found_by_the_returns_lookup(): void
    {
        $sale = $this->ring('branch', [$this->line('40', 'branch', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->void($this->branchOwner, $sale)->assertSessionHas('success');

        $this->as($this->branchOwner)->getJson(route('app.returns.lookup', ['sale_id' => $sale->id]))->assertOk()->assertJson(['sales' => []]);

        $r = $this->process($this->branchOwner, ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'x', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]]);
        $this->assertGreaterThanOrEqual(400, $r->status(), 'a return against a voided bill is refused');
        $this->assertSame(0, SalesReturn::withoutGlobalScopes()->count());
        $this->assertPool(10, 10, 20, 'void restored the stock once, the refused return did not add more');
        $this->assertMoney($this->branch, 5000, 0);
    }

    public function test_cross_branch_scan_lookup_then_return_books_stock_and_cash_at_the_receiving_branch(): void
    {
        // sold at MAIN …
        $sale = $this->ring('main', [$this->line('40', 'main', 1)], [['method' => 'cash', 'amount' => 1000]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertMoney($this->main, 11000, 0);

        // … customer walks into the BRANCH. Scan the sticker (ZV short code of the branch's own copy)
        $scan = $this->as($this->branchOwner)->getJson(route('app.returns.barcode', 'ZV'.$this->v40B->id))->assertOk();
        $this->assertTrue((bool) $scan->json('found'));
        $look = $this->as($this->branchOwner)->getJson(route('app.returns.lookup', ['q' => 'ZV'.$this->v40B->id]))->assertOk();
        $found = collect($look->json('sales'))->firstWhere('id', $sale->id);
        $this->assertNotNull($found, 'the main-shop bill is offered at the branch');
        $this->assertSame('Baby Park', $found['shop_name'], 'and shows which shop sold it');
        $this->assertNotEmpty($look->json('resolved'));
        $byInvoice = $this->as($this->branchOwner)->getJson(route('app.returns.lookup', ['q' => $sale->invoice_no]))->assertOk();
        $this->assertCount(1, $byInvoice->json('sales'));

        $this->process($this->branchOwner, ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'cross', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]])->assertOk();

        $this->assertPool(10, 10, 20, 'back on the shelf, visible in both branches');
        $this->assertMoney($this->branch, 4000, 0, 'the RECEIVING branch pays the refund out of its till');
        $this->assertMoney($this->main, 11000, 0, 'the selling branch\'s till is not touched');
        $ret = SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame($this->branch->id, (int) $ret->shop_id, 'the return is booked where it physically happened');

        // the selling branch cannot now void the bill (books would disagree)
        $this->void($this->mainOwner, $sale)->assertSessionHas('error');
        $this->assertPool(10, 10, 20);
        $this->assertMoney($this->main, 11000, 0);
    }

    public function test_cross_branch_exchange_takes_the_replacement_from_the_pool_and_money_at_the_receiving_branch(): void
    {
        $sale = $this->ring('main', [$this->line('polish', 'main', 1)], [['method' => 'cash', 'amount' => 80]]);
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $this->process($this->branchOwner, [
            'sale_id' => $sale->id, 'action' => 'exchange', 'reason' => 'swap',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [$this->line('41', 'branch', 1)],
            'payments' => [['method' => 'cash', 'amount' => 920]],
        ])->assertOk();

        $this->assertPool(10, 9, 20);
        $this->assertMoney($this->branch, 5920, 0, 'branch collected the difference');
        $this->assertMoney($this->main, 10080, 0, 'main keeps only its original 80');
        $ex = Sale::withoutGlobalScopes()->where('sale_type', 'exchange')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $ex->shop_id);
    }

    // ==================================================================
    // 5. ONLINE ORDER
    // ==================================================================

    private function channel(Shop $s): SalesChannel
    {
        return SalesChannel::withoutGlobalScopes()->create(['shop_id' => $s->id, 'name' => 'Page '.$s->id, 'platform_type' => 'facebook', 'order_prefix' => 'FB'.$s->id.'-']);
    }

    private function place(User $u, SalesChannel $ch, string $phone, array $items, array $extra = [])
    {
        return $this->as($u)->post(route('app.onlineOrders.store'), array_merge(['channel_id' => $ch->id, 'customer_phone' => $phone, 'customer_name' => 'Karim', 'items' => $items], $extra));
    }

    private function orderStatus(OnlineOrder $o, string $status)
    {
        return $this->as($this->branchOwner)->patch(route('app.onlineOrders.transition', $o->id), ['status' => $status]);
    }

    public function test_online_order_lifecycle_counts_in_reports_and_customer_stats_only_when_delivered(): void
    {
        $ch = $this->channel($this->branch);
        $this->place($this->branchOwner, $ch, '01811110000', [$this->line('40', 'branch', 2)], ['cod_amount' => 2000])->assertRedirect();
        $order = OnlineOrder::withoutGlobalScopes()->firstOrFail();
        $sale = $this->sale($order->sale_id);

        // placed: stock is taken in BOTH branches straight away, but it is not a report sale yet
        $this->assertSame('pending', $order->status);
        $this->assertSame($this->branch->id, (int) $sale->shop_id);
        $this->assertEquals(2000, (float) $sale->total);
        $this->assertEquals(800, (float) $sale->profit);
        $this->assertFalse((bool) $sale->counted_in_reports);
        $this->assertPool(8, 10, 20, 'reserved pool-wide at placement');
        $this->assertMoney($this->branch, 5000, 0, 'COD is with the courier, not in the till');
        $st = $this->stats($this->branch);
        $this->assertSame(0, $st['count']);
        $this->assertEquals(0, $st['grossSales'], 'pending order invisible to reports');
        $cust = Customer::withoutGlobalScopes()->where('phone', '01811110000')->first();
        $this->assertNotNull($cust, 'the order created its customer');
        $this->assertEquals(0, (float) $cust->total_spent);
        $this->assertSame(1, $this->logs('online_order.created'));

        // status walk — nothing financial moves
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $s) {
            $this->orderStatus($order, $s)->assertRedirect()->assertSessionMissing('error');
            $this->assertSame($s, $order->fresh()->status);
        }
        $this->assertSame(4, $this->logs('online_order.status'));
        $this->assertSame(0, $this->stats($this->branch)['count'], 'still invisible while in transit');
        $this->assertPool(8, 10, 20);
        $this->assertMoney($this->branch, 5000, 0);

        // delivered: NOW it counts, once
        $this->as($this->branchOwner)->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertTrue((bool) $this->sale($sale->id)->counted_in_reports);
        $st = $this->stats($this->branch);
        $this->assertSame(1, $st['count']);
        $this->assertEquals(2000, $st['grossSales']);
        $this->assertEquals(800, $st['grossProfit']);
        $cust = $this->cfresh($cust);
        $this->assertEquals(2000, (float) $cust->total_spent);
        $this->assertSame(1, (int) $cust->visits);
        $this->assertSame(1, $this->logs('online_order.delivered'));
        $this->assertPool(8, 10, 20, 'delivery does not touch stock again');

        // delivering twice must not double-count
        $this->as($this->branchOwner)->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(2000, (float) $this->cfresh($cust)->total_spent);
        $this->assertSame(1, $this->stats($this->branch)['count']);

        // a delivered order cannot be cancelled
        $this->as($this->branchOwner)->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'changed mind'])->assertRedirect()->assertSessionHas('error');
        $this->assertPool(8, 10, 20, 'no restock of a delivered order');
    }

    public function test_cancelled_online_order_restores_stock_in_both_branches_and_prepaid_money(): void
    {
        $ch = $this->channel($this->branch);
        $this->place($this->branchOwner, $ch, '01822220000', [$this->line('41', 'branch', 1), $this->line('polish', 'branch', 2)], ['paid_amount' => 500, 'paid_method' => 'bkash', 'cod_amount' => 660])->assertRedirect();
        $order = OnlineOrder::withoutGlobalScopes()->firstOrFail();

        $this->assertPool(10, 9, 18);
        $this->assertMoney($this->branch, 5000, 500, 'the prepaid 500 really is in the bank');

        $this->orderStatus($order, 'confirmed')->assertSessionMissing('error');
        $this->as($this->branchOwner)->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'customer refused'])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotNull($this->sale($order->sale_id)->voided_at);
        $this->assertPool(10, 10, 20, 'stock back in both branches');
        $this->assertMoney($this->branch, 5000, 0, 'prepaid money reversed, COD never touched the till');
        $this->assertSame(1, $this->logs('online_order.cancelled'));
        $this->assertSame(0, $this->stats($this->branch)['count']);
        $cust = Customer::withoutGlobalScopes()->where('phone', '01822220000')->first();
        $this->assertEquals(0, (float) $cust->total_spent);

        // cancelling again is refused, no second restock
        $this->as($this->branchOwner)->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'again again'])->assertRedirect()->assertSessionHas('error');
        $this->assertPool(10, 10, 20);
    }

    // ==================================================================
    // NON-POOLED shop — the same core flow, no sibling to mirror to
    // ==================================================================

    public function test_a_plain_single_shop_checkout_void_return_and_exchange_all_update_every_layer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        foreach (['product_variants', 'returns'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Sandal', 'cost' => 300, 'price' => 500, 'stock' => 6]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '42', 'stock' => 6, 'cost' => null]);
        $q = Product::create(['shop_id' => $shop->id, 'name' => 'Lace', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        $c = $this->cust($shop);
        $val = fn () => $this->stockValue($shop);
        Tenancy::clear();
        $this->assertEqualsWithDelta(6 * 300 + 50 * 10, $val(), 0.01);

        // checkout, split tender + due
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'customer_id' => $c->id,
            'items' => [['product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 2], ['product_id' => $q->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 600], ['method' => 'bkash', 'amount' => 300]],
        ])->assertOk();
        $sale = $this->sale($r->json('sale.id'));
        $this->assertEquals(1100, (float) $sale->total);
        $this->assertEquals((500 - 300) * 2 + (20 - 10) * 5, (float) $sale->profit, 'variant cost NULL -> product cost 300');
        $this->assertMoney($shop, 1600, 300, 'cash and bank separately');
        $this->assertEquals(200, (float) $this->cfresh($c)->due);
        $this->assertEquals(4, (float) Product::withoutGlobalScopes()->find($p->id)->stock);
        $this->assertEquals(4, (float) ProductVariant::withoutGlobalScopes()->find($v->id)->stock);
        $this->assertEquals(45, (float) Product::withoutGlobalScopes()->find($q->id)->stock);
        $this->assertEqualsWithDelta(4 * 300 + 45 * 10, $val(), 0.01);

        // return one sandal (refund first reduces the due)
        $item = \App\Models\SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->where('product_id', $p->id)->first();
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($owner, 'web')->postJson('/app/returns/process', ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'size', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]])->assertOk();
        $this->assertEquals(5, (float) Product::withoutGlobalScopes()->find($p->id)->stock);
        $this->assertEquals(5, (float) ProductVariant::withoutGlobalScopes()->find($v->id)->stock);
        $dueDrop = 200 - (float) $this->cfresh($c)->due;
        [$cash, $bank] = $this->money($shop);
        $this->assertEqualsWithDelta(500, $dueDrop + (1600 - $cash) + (300 - $bank), 0.01, 'the 500 refund went to due/cash, nothing lost');
        $this->assertEqualsWithDelta(5 * 300 + 45 * 10, $val(), 0.01);

        // void the bill — blocked? no exchange was made, so it reverses what is left
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'rest cancelled'])->assertSessionHas('success');
        $this->assertEquals(6, (float) Product::withoutGlobalScopes()->find($p->id)->stock, 'returned pair not restocked twice');
        $this->assertEquals(6, (float) ProductVariant::withoutGlobalScopes()->find($v->id)->stock);
        $this->assertEquals(50, (float) Product::withoutGlobalScopes()->find($q->id)->stock);
        $this->assertEqualsWithDelta(6 * 300 + 50 * 10, $val(), 0.01, 'stock value back to the start');
        $this->assertEquals(0, (float) $this->cfresh($c)->due);
        $this->assertEquals(0, (float) $this->cfresh($c)->total_spent);
        $this->assertEqualsWithDelta(1000, array_sum($this->money($shop)), 0.01, 'all money back where it started (1000 cash)');
        $this->assertSame(1, ActivityLog::withoutGlobalScopes()->where('action', 'sale.void')->count());
    }
}
