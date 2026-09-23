<?php

namespace Tests\Feature;

use App\Models\Damage;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Damage, stock-count reconciliation, and sales returns each already wrote
 * a permanent, timestamped row - but nothing in the app let an owner
 * actually browse "what happened and when" for any of them. Once recorded,
 * an entry was invisible again except as a lump sum on Accounts/Reports.
 */
class StockHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_damage_tab_lists_entries_with_product_and_loss(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);
        Damage::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 5, 'reason' => 'মেয়াদ শেষ', 'loss' => 50, 'date' => now()]);

        $response = $this->actingAs($owner, 'web')->get('/app/stock-history?type=damage');

        $records = $response->getOriginalContent()->getData()['page']['props']['records']['data'];
        $this->assertCount(1, $records);
        $this->assertSame('Rice', $records[0]['product']['name']);
        $this->assertSame(50.0, (float) $records[0]['loss']);
    }

    public function test_count_tab_resolves_product_names_for_each_change(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sugar', 'cost' => 10, 'price' => 20, 'stock' => 95]);
        StockCount::create([
            'shop_id' => $shop->id, 'date' => now(), 'changed' => 1,
            'changes' => [['product_id' => $product->id, 'from' => 100, 'to' => 95]],
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/stock-history?type=count');

        $records = $response->getOriginalContent()->getData()['page']['props']['records']['data'];
        $this->assertCount(1, $records);
        $this->assertSame('Sugar', $records[0]['changes'][0]['product_name']);
        $this->assertSame(100, $records[0]['changes'][0]['from']);
        $this->assertSame(95, $records[0]['changes'][0]['to']);
    }

    public function test_return_tab_lists_entries_with_product_user_and_refund(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 10, 'price' => 20, 'stock' => 100]);
        SalesReturn::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'user_id' => $owner->id,
            'qty' => 2, 'refund' => 40, 'phone' => '01700000000', 'date' => now(),
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/stock-history?type=return');

        $records = $response->getOriginalContent()->getData()['page']['props']['records']['data'];
        $this->assertCount(1, $records);
        $this->assertSame('Oil', $records[0]['product']['name']);
        $this->assertSame($owner->name, $records[0]['user']['name']);
        $this->assertSame(40.0, (float) $records[0]['refund']);
    }

    public function test_history_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Not Yours', 'cost' => 10, 'price' => 20, 'stock' => 100]);
        Damage::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'qty' => 1, 'loss' => 10, 'date' => now()]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/stock-history?type=damage');

        $records = $response->getOriginalContent()->getData()['page']['props']['records']['data'];
        $this->assertCount(0, $records);
    }
}
