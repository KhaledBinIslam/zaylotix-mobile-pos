<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * QA Phase 3 fixture. Everything here reads state straight from the tables with the query builder, never through the
 * application's own report/ledger code — that is what makes the assertions an INDEPENDENT oracle.
 */
trait Phase3Fixture
{
    use CreatesShops;

    protected Shop $shop;

    protected User $owner;

    protected function p3Boot(array $features = [], array $shopAttrs = []): void
    {
        [$this->shop, $this->owner] = $this->createShopWithOwner(array_merge(['cash_balance' => 10000, 'bank_balance' => 0], $shopAttrs));
        foreach (array_merge(['returns', 'accounts', 'product_variants', 'damages', 'stock_count', 'suppliers', 'purchases'], $features) as $f) {
            $this->grantFeature($this->shop, $f);
        }
    }

    /** The logged-in owner, with tenancy reset (the app memoises the shop per request; tests make several). */
    protected function p3As(): static
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();

        return $this->actingAs($this->owner, 'web');
    }

    protected function p3Product(string $name, float $cost, float $price, float $stock, array $extra = []): Product
    {
        return Product::create(array_merge(['shop_id' => $this->shop->id, 'name' => $name, 'cost' => $cost, 'price' => $price, 'stock' => $stock], $extra));
    }

    protected function p3Variant(Product $p, string $size, int $stock, array $extra = []): ProductVariant
    {
        $v = ProductVariant::create(array_merge(['shop_id' => $this->shop->id, 'product_id' => $p->id, 'size' => $size, 'color' => 'Black', 'stock' => $stock], $extra));
        $p->forceFill(['stock' => (float) DB::table('product_variants')->where('product_id', $p->id)->whereNull('deleted_at')->sum('stock')])->save();

        return $v;
    }

    protected function p3Customer(string $phone = '01711111111', float $due = 0): Customer
    {
        return Customer::create(['shop_id' => $this->shop->id, 'name' => 'QA Customer', 'phone' => $phone, 'due' => $due]);
    }

    protected function p3Supplier(string $name = 'QA Supplier', float $payable = 0): Supplier
    {
        return Supplier::create(['shop_id' => $this->shop->id, 'name' => $name, 'payable' => $payable]);
    }

    /** Every figure a transaction can move, read raw. */
    protected function p3Snap(): array
    {
        $f = fn ($c) => $c->map(fn ($v) => round((float) $v, 4))->all();
        $id = $this->shop->id;

        return [
            'cash' => round((float) DB::table('shops')->where('id', $id)->value('cash_balance'), 4),
            'bank' => round((float) DB::table('shops')->where('id', $id)->value('bank_balance'), 4),
            'product_stock' => $f(DB::table('products')->where('shop_id', $id)->orderBy('id')->pluck('stock', 'id')),
            'variant_stock' => $f(DB::table('product_variants')->where('shop_id', $id)->whereNull('deleted_at')->orderBy('id')->pluck('stock', 'id')),
            'customer_due' => $f(DB::table('customers')->where('shop_id', $id)->orderBy('id')->pluck('due', 'id')),
            'loyalty_points' => DB::table('customers')->where('shop_id', $id)->orderBy('id')->pluck('loyalty_points', 'id')->map(fn ($v) => (int) $v)->all(),
            'supplier_payable' => $f(DB::table('suppliers')->where('shop_id', $id)->orderBy('id')->pluck('payable', 'id')),
        ];
    }

    protected function p3AssertSame(array $expected, array $actual, string $why = ''): void
    {
        $this->assertEquals($expected, $actual, 'state differs'.($why ? " — {$why}" : '').' '.json_encode(['expected' => $expected, 'actual' => $actual]));
    }

    protected function p3Checkout(array $items, array $payments, array $extra = [])
    {
        return $this->p3As()->postJson('/app/pos/checkout', array_merge(['items' => $items, 'payments' => $payments], $extra));
    }

    /** rings a sale that MUST succeed; returns the Sale row (including voided ones) */
    protected function p3Sell(array $items, array $payments, array $extra = []): Sale
    {
        $r = $this->p3Checkout($items, $payments, $extra);
        $this->assertSame(200, $r->status(), 'checkout failed: '.substr((string) $r->getContent(), 0, 300));

        return Sale::withoutGlobalScopes()->findOrFail($r->json('sale.id'));
    }

    protected function p3Void(Sale $sale, string $reason = 'QA wrong entry')
    {
        return $this->p3As()->delete("/app/sales/{$sale->id}", ['reason' => $reason]);
    }

    protected function p3Return(Sale $sale, array $lines, string $action = 'refund', array $extra = [])
    {
        return $this->p3As()->postJson('/app/returns/process', array_merge([
            'sale_id' => $sale->id, 'action' => $action, 'reason' => 'QA', 'lines' => $lines,
        ], $extra));
    }

    protected function p3ItemId(Sale $sale, int $nth = 0): int
    {
        return (int) DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->skip($nth)->value('id');
    }
}
