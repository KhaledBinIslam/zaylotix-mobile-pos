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
 * Khaled: "2 shop e stock ektai" — one stock pool, everything else
 * (who sold what, for how much, at what profit) per shop.
 *
 * Deleting an article at the main shop takes the branch's copy down with
 * it. Entering that article again found the tombstone by name, updated
 * it, and left it deleted — so Baby Park had the shoes and Liberty
 * showed nothing. Measured on production: B-20007 and B-20008 live at
 * shop 80, their branch copies soft-deleted at shop 81 with the master
 * alive, and the two shops' stock values ৳5,800 apart.
 */
class CatalogSyncRestoresBranchCopyTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** @return array{0: Shop, 1: Shop} main shop (shared_catalog + shared_stock_pool) and its branch */
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

    public function test_entering_a_deleted_article_again_brings_it_back_at_the_branch(): void
    {
        [$shop, $branch] = $this->aChain();

        $first = Product::create(['shop_id' => $shop->id, 'name' => 'B-20007', 'cost' => 100, 'price' => 200, 'stock' => 16]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $first->id, 'size' => '22', 'stock' => 16]);
        CatalogSync::syncToBranch($shop, $branch);

        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->firstOrFail();
        $this->assertNull($copy->deleted_at);

        // deleted at the main shop — the branch's copy goes with it
        $first->delete();
        $this->assertNotNull(Product::withoutGlobalScopes()->whereKey($copy->id)->value('deleted_at'));

        // and entered again, on the same article number
        $second = Product::create(['shop_id' => $shop->id, 'name' => 'B-20007', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $second->id, 'size' => '22', 'stock' => 2]);
        CatalogSync::syncToBranch($shop, $branch);

        $revived = Product::withoutGlobalScopes()->whereKey($copy->id)->first();
        $this->assertNull($revived->deleted_at, 'the branch has to get the article back');
        $this->assertSame($second->id, $revived->master_product_id);
        // the 16 belonged to the article that was deleted; this is a
        // different one, so the branch starts from its count, not that
        $this->assertEquals(2, $revived->stock);
        $this->assertEquals(2, ProductVariant::withoutGlobalScopes()
            ->where('product_id', $revived->id)->whereNull('deleted_at')->sum('stock'));
    }

    public function test_a_live_branch_copy_keeps_its_own_stock_as_before(): void
    {
        [$shop, $branch] = $this->aChain();

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        CatalogSync::syncToBranch($shop, $branch);

        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->firstOrFail();
        Product::withoutGlobalScopes()->whereKey($copy->id)->update(['stock' => 7]);

        // a sync has never overwritten a live branch row's own count
        $product->update(['price' => 250]);
        CatalogSync::syncToBranch($shop, $branch);

        $after = Product::withoutGlobalScopes()->whereKey($copy->id)->first();
        $this->assertEquals(7, $after->stock);
        $this->assertEquals(250, $after->price);
    }
}
