<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "jekhan tekhe uthuk stock 2 tar ekta ebong etai dekhabe, eta
 * tekhe 2 jon sell korbe" — one stock for the business, whichever shop
 * entered it, both selling from it.
 *
 * Syncing only ever went main → branch, so an article or a size entered
 * at Liberty stayed at Liberty and the two shops stopped agreeing.
 */
class CatalogSyncBusinessTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** @return array{0: Shop, 1: Shop} */
    private function aChain(): array
    {
        [$shop] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'shared_catalog');
        $this->grantFeature($shop, 'shared_stock_pool');
        $this->grantFeature($shop, 'product_variants');

        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $shop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);

        return [$shop->fresh(), $branch];
    }

    public function test_an_article_entered_at_the_branch_reaches_the_main_shop(): void
    {
        [$shop, $branch] = $this->aChain();

        $atBranch = Product::create([
            'shop_id' => $branch->id, 'name' => 'B-20009', 'cost' => 100, 'price' => 200, 'stock' => 4,
        ]);
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $atBranch->id, 'size' => '16', 'color' => 'BROWN', 'stock' => 4]);

        CatalogSync::syncBusiness($shop);

        $atMain = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'B-20009')->first();
        $this->assertNotNull($atMain, 'the main shop has to end up holding it too');
        $this->assertEquals(4, $atMain->stock);
        $this->assertEquals(4, ProductVariant::withoutGlobalScopes()
            ->where('product_id', $atMain->id)->whereNull('deleted_at')->sum('stock'));

        // and the branch's own row is now a copy of it, so from here on
        // the pool keeps the two counts in step
        $this->assertSame($atMain->id, $atBranch->fresh()->master_product_id);
    }

    public function test_a_size_added_at_the_branch_reaches_the_main_shop(): void
    {
        [$shop, $branch] = $this->aChain();

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'B-20009', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'BROWN', 'stock' => 5]);
        CatalogSync::syncToBranch($shop, $branch);

        // the branch grows a size of its own
        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->firstOrFail();
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $copy->id, 'size' => '17', 'color' => 'BROWN', 'stock' => 3]);
        Product::withoutGlobalScopes()->whereKey($copy->id)->update(['stock' => 8]);

        CatalogSync::syncBusiness($shop);

        $mainSizes = ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)->whereNull('deleted_at')->pluck('stock', 'size');
        $this->assertEquals(5, $mainSizes['16']);
        $this->assertEquals(3, $mainSizes['17'], 'the size the branch added has to come up');
        // products.stock is the live sum of its sizes
        $this->assertEquals(8, Product::withoutGlobalScopes()->whereKey($product->id)->value('stock'));
    }

    public function test_both_shops_end_up_with_the_same_sizes_and_the_same_count(): void
    {
        [$shop, $branch] = $this->aChain();

        $atMain = Product::create(['shop_id' => $shop->id, 'name' => 'B-20007', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $atMain->id, 'size' => '16', 'color' => 'WHITE', 'stock' => 2]);

        $atBranch = Product::create(['shop_id' => $branch->id, 'name' => 'B-20009', 'cost' => 100, 'price' => 200, 'stock' => 4]);
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $atBranch->id, 'size' => '20', 'color' => 'BROWN', 'stock' => 4]);

        CatalogSync::syncBusiness($shop);

        foreach ([$shop->id, $branch->id] as $shopId) {
            $names = Product::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->pluck('name')->sort()->values()->all();
            $this->assertSame(['B-20007', 'B-20009'], $names, "shop {$shopId} should carry both articles");
            $this->assertEquals(6, ProductVariant::withoutGlobalScopes()
                ->where('shop_id', $shopId)->whereNull('deleted_at')->sum('stock'), "shop {$shopId} should hold the same count");
        }
    }

    public function test_saving_one_article_does_not_walk_the_whole_catalogue(): void
    {
        [$shop, $branch] = $this->aChain();
        $this->grantFeature($shop, 'article_series');

        // a catalogue the size Baby Park's had grown to when adding an
        // article sat on "Adding…" for fifteen minutes
        for ($i = 1; $i <= 40; $i++) {
            $p = Product::create(['shop_id' => $shop->id, 'name' => "OLD-{$i}", 'cost' => 10, 'price' => 20, 'stock' => 2]);
            ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '36', 'color' => 'RED', 'stock' => 2]);
        }
        CatalogSync::syncToBranch($shop, $branch);

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });

        $fresh = Product::create(['shop_id' => $shop->id, 'name' => 'NEW-1', 'cost' => 10, 'price' => 20, 'stock' => 1]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $fresh->id, 'size' => '36', 'color' => 'RED', 'stock' => 1]);
        CatalogSync::syncOneProduct($shop, $fresh);

        // the branch gets it
        $this->assertSame(41, Product::withoutGlobalScopes()->where('shop_id', $branch->id)->whereNull('deleted_at')->count());
        $this->assertNotNull(Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'NEW-1')->first());

        // and the cost does not scale with the catalogue: syncBusiness()
        // on this same data runs well into four figures of queries
        $this->assertLessThan(60, $queries, "one article's sync should not walk every product");
    }

    public function test_a_shop_with_no_shared_catalog_is_left_alone(): void
    {
        [$shop] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $shop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        Product::create(['shop_id' => $branch->id, 'name' => 'Local', 'cost' => 10, 'price' => 20, 'stock' => 1]);

        CatalogSync::syncBusiness($shop);

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }
}
