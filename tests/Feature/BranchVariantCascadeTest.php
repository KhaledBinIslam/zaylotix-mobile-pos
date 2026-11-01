<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Deleting a whole article reached the branch's copy of it (74bff6ae);
 * deleting a single SIZE did not. So removing 22/WHITE at Baby Park left
 * Liberty still selling it, and when that size was entered again
 * CatalogSync handed the branch a SECOND row for it. Measured on
 * production: eight sizes listed twice, products.stock 32 against a
 * variant sum of 43 — eleven units that were never on a shelf.
 */
class BranchVariantCascadeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** @return array{0: Shop, 1: User, 2: Shop, 3: Product, 4: ProductVariant, 5: ProductVariant} main shop, owner, branch, main product, main variant, the branch's copy of it */
    private function aChain(bool $pooled): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'shared_catalog');
        if ($pooled) {
            $this->grantFeature($shop, 'shared_stock_pool');
        }

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

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 200, 'price' => 400, 'stock' => 5]);
        $variant = ProductVariant::create([
            'shop_id' => $shop->id, 'product_id' => $product->id,
            'size' => '22', 'color' => 'WHITE', 'stock' => 5, 'barcode' => 'A-1-WHT-22',
        ]);

        $branchProduct = Product::create([
            'shop_id' => $branch->id, 'name' => 'Shoe', 'cost' => 200, 'price' => 400, 'stock' => 5,
            'master_product_id' => $product->id,
        ]);
        $branchVariant = ProductVariant::create([
            'shop_id' => $branch->id, 'product_id' => $branchProduct->id,
            'size' => '22', 'color' => 'WHITE', 'stock' => 5, 'barcode' => 'A-1-WHT-22',
        ]);

        return [$shop, $owner, $branch, $product, $variant, $branchVariant];
    }

    public function test_removing_a_size_removes_the_branchs_copy_of_it(): void
    {
        [$shop, $owner, $branch, $product, $variant, $branchVariant] = $this->aChain(pooled: false);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$variant->id}")->assertRedirect();

        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->value('deleted_at'));
    }

    public function test_an_unpooled_branch_loses_those_units_from_its_own_product(): void
    {
        [$shop, $owner, $branch, $product, $variant, $branchVariant] = $this->aChain(pooled: false);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$variant->id}")->assertRedirect();

        // products.stock is the live sum of its variants, and the branch
        // counts its own — so its five units leave with the row
        $branchProduct = Product::withoutGlobalScopes()->whereKey($branchVariant->product_id)->first();
        $this->assertEquals(0, $branchProduct->stock);
        $this->assertEquals(0, ProductVariant::withoutGlobalScopes()
            ->where('product_id', $branchProduct->id)->whereNull('deleted_at')->sum('stock'));
    }

    public function test_a_pooled_branch_is_not_charged_for_the_same_units_twice(): void
    {
        [$shop, $owner, $branch, $product, $variant, $branchVariant] = $this->aChain(pooled: true);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$variant->id}")->assertRedirect();

        // one real number, kept in lockstep: the main shop's own delete
        // already took these five off for both shops, so taking them off
        // again here would count them twice
        $branchProduct = Product::withoutGlobalScopes()->whereKey($branchVariant->product_id)->first();
        $this->assertEquals(0, $branchProduct->stock);
        $this->assertEquals(0, ProductVariant::withoutGlobalScopes()
            ->where('product_id', $branchProduct->id)->whereNull('deleted_at')->sum('stock'));
    }

    public function test_a_shop_with_no_branches_is_untouched(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 200, 'price' => 400, 'stock' => 5]);
        $variant = ProductVariant::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '22', 'stock' => 5,
        ]);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$variant->id}")->assertRedirect();

        $this->assertEquals(0, $product->fresh()->stock);
        $this->assertSame(1, ProductVariant::withoutGlobalScopes()->count());
    }

    public function test_the_dedupe_command_keeps_the_row_that_carries_the_barcode(): void
    {
        [$shop, $owner, $branch, $product, $variant, $branchVariant] = $this->aChain(pooled: true);

        // exactly what production had: a second row for the same size,
        // left behind when the main shop's copy was deleted and re-added
        $stray = ProductVariant::create([
            'shop_id' => $branch->id, 'product_id' => $branchVariant->product_id,
            'size' => '22', 'color' => 'WHITE', 'stock' => 5, 'barcode' => null,
        ]);

        $this->artisan('products:dedupe-variants', ['--shop' => $branch->id, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($stray->fresh()->deleted_at, 'a dry run must not change anything');

        $this->artisan('products:dedupe-variants', ['--shop' => $branch->id])->assertSuccessful();

        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->whereKey($stray->id)->value('deleted_at'));
        $this->assertNull(ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->value('deleted_at'));
        $this->assertSame('A-1-WHT-22', ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->value('barcode'));
    }
}
