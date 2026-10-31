<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\ArticleSerial;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Deleting an article at a main shop with `shared_catalog` only ever
 * removed the main shop's own row. The branch's copy stayed live, so the
 * branch went on selling an article the owner had removed — and, since
 * ArticleSerial counts a serial as used anywhere in the business, its
 * number never came free either.
 *
 * Khaled, twice: "delete kore dileo 7 8 ekhono aseni", with
 * articles:settings-doctor printing all ten serials live and none freed.
 */
class DeletedArticleReachesTheBranchTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** @return array{0: Shop, 1: User, 2: Shop, 3: Product} a main shop with shared_catalog, its owner, one branch, and one article synced into it */
    private function aChainWithOneArticle(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $this->grantFeature($shop, 'shared_catalog');

        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $shop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        User::create([
            'shop_id' => $branch->id, 'name' => 'Branch Owner', 'phone' => $branch->phone,
            'password' => 'password', 'role' => 'owner', 'lang' => 'bn',
        ]);

        $this->actingAs($owner, 'web')->post('/app/articles', [
            'client_uuid' => (string) Str::uuid(),
            'manufacturer_code' => 'SMS',
            'new_category_name' => 'BABY',
            'category_code' => 'B',
            'cost' => 1300,
            'price' => 1990,
            'variants' => [['color' => 'PINK', 'size' => '22', 'qty' => 2]],
        ])->assertSessionHasNoErrors();

        $product = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->firstOrFail();
        CatalogSync::syncToBranch($shop->fresh(), $branch);

        return [$shop->fresh(), $owner, $branch, $product];
    }

    public function test_deleting_an_article_takes_the_branchs_copy_with_it(): void
    {
        [$shop, $owner, $branch, $product] = $this->aChainWithOneArticle();

        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->first();
        $this->assertNotNull($copy, 'the branch should have a copy before the delete');

        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();

        $this->assertNotNull(Product::withoutGlobalScopes()->whereKey($copy->id)->first()->deleted_at);
        // and the copy's own sizes went with it, exactly as the main
        // shop's did — a sticker for one of them must not keep resolving
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('product_id', $copy->id)->whereNull('deleted_at')->count());
    }

    public function test_the_serial_comes_free_once_the_branch_copy_is_gone_too(): void
    {
        [$shop, $owner, $branch, $product] = $this->aChainWithOneArticle();

        // the whole point: a serial is counted business-wide, so a live
        // branch copy kept the number reserved after the owner deleted it
        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();

        $used = ArticleSerial::serialsUsed($shop, 'B');
        $this->assertSame([], $used['live']);
        $this->assertSame([10001], $used['freed']);
        $this->assertSame(10001, ArticleSerial::nextFree($shop, 'B'));
    }

    public function test_a_resync_never_hands_a_branch_a_deleted_article_back(): void
    {
        [$shop, $owner, $branch, $product] = $this->aChainWithOneArticle();

        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();
        CatalogSync::syncToBranch($shop, $branch);

        $this->assertSame(
            0,
            Product::withoutGlobalScopes()->where('shop_id', $branch->id)->whereNull('deleted_at')->count()
        );
    }

    public function test_restoring_the_article_brings_the_branchs_copy_back(): void
    {
        [$shop, $owner, $branch, $product] = $this->aChainWithOneArticle();

        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();
        Product::withoutGlobalScopes()->whereKey($product->id)->first()->restore();

        $this->assertSame(
            1,
            Product::withoutGlobalScopes()->where('shop_id', $branch->id)->whereNull('deleted_at')->count()
        );
    }

    public function test_the_migration_clears_copies_stranded_before_this_existed(): void
    {
        [$shop, $owner, $branch, $product] = $this->aChainWithOneArticle();

        // exactly the old behaviour: the main row deleted, the branch's
        // copy left standing (no model events, so no cascade)
        Product::withoutGlobalScopes()->whereKey($product->id)->update(['deleted_at' => now()]);
        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->firstOrFail();
        $this->assertNull($copy->deleted_at);

        (require base_path('database/migrations/2026_10_31_000004_delete_branch_copies_of_deleted_products.php'))->up();

        $this->assertNotNull(Product::withoutGlobalScopes()->whereKey($copy->id)->first()->deleted_at);
        $this->assertSame(10001, ArticleSerial::nextFree($shop, 'B'));
    }

    public function test_a_shop_with_no_branches_is_untouched(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();

        $this->assertNotNull(Product::withoutGlobalScopes()->whereKey($product->id)->first()->deleted_at);
        $this->assertSame(1, Product::withoutGlobalScopes()->count());
    }
}
