<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The online order form's scan-to-add server fallback
 * (OnlineOrderController::barcode -> BarcodeResolver). Baby Park live,
 * 2026-11-23: a sticker printed at the branch (bars = ZV{branch variant id})
 * scanned at the main shop's order form said "No products found", because
 * the form only matched against its own shop's products client-side. This
 * endpoint is the same shared resolver POS and Returns already use, in the
 * online-business route group (its own perm/feature, not perm:pos).
 */
class OnlineOrderBarcodeLookupTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function shopWithArticle(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $product = Product::create(['shop_id' => $shop->id, 'name' => '80001', 'barcode' => '80001-IXXZ', 'cost' => 350, 'price' => 500, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40-41', 'color' => 'WHITE-BLUE', 'barcode' => 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', 'stock' => 6]);

        return [$shop, $owner, $product, $variant];
    }

    /** The live case: a sticker printed at branch B (ZV{B's variant id}) scanned on branch A's order form resolves to A's own matching size. */
    public function test_a_sibling_branchs_zv_sticker_resolves_to_this_shops_matching_variant(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Liberty-Agrabad', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);
        $mainOwner = $main->users()->first();
        $this->grantFeature($main, 'online_business');

        $mp = Product::create(['shop_id' => $main->id, 'name' => '80001', 'barcode' => '80001-IXXZ', 'cost' => 350, 'price' => 500, 'stock' => 6]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mp->id, 'size' => '40-41', 'color' => 'WHITE-BLUE', 'barcode' => 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', 'stock' => 6]);
        $bp = Product::create(['shop_id' => $branch->id, 'name' => '80001', 'master_product_id' => $mp->id, 'barcode' => '80001-IXXZ', 'cost' => 350, 'price' => 500, 'stock' => 6]);
        $branchVariant = ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $bp->id, 'size' => '40-41', 'color' => 'WHITE-BLUE', 'barcode' => 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', 'stock' => 6]);

        // the label was printed at the BRANCH (bars encode the branch's variant id), scanned at the MAIN shop
        $this->actingAs($mainOwner, 'web')->getJson('/app/online-orders/barcode/ZV'.$branchVariant->id)
            ->assertOk()
            ->assertJson(['found' => true, 'variant_id' => $mainVariant->id])
            ->assertJsonPath('product.id', $mp->id);
    }

    public function test_own_shop_zv_code_resolves(): void
    {
        [, $owner, $product, $variant] = $this->shopWithArticle();

        $this->actingAs($owner, 'web')->getJson('/app/online-orders/barcode/ZV'.$variant->id)
            ->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id])->assertJsonPath('product.id', $product->id);
    }

    public function test_a_sizes_long_barcode_resolves_with_its_variant_id(): void
    {
        [, $owner, $product, $variant] = $this->shopWithArticle();

        $this->actingAs($owner, 'web')->getJson('/app/online-orders/barcode/CHINA-S-80001-IXXZ-WHITE-BLUE-40-41')
            ->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id])->assertJsonPath('product.id', $product->id);
    }

    public function test_a_color_barcode_and_its_zc_code_resolve_with_the_color(): void
    {
        [$shop, $owner, $product] = $this->shopWithArticle();
        $cb = ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'WHITE-BLUE', 'barcode' => 'CHINA-S-80001-IXXZ-WHITE-BLUE']);

        foreach (['CHINA-S-80001-IXXZ-WHITE-BLUE', 'ZC'.$cb->id] as $code) {
            $this->actingAs($owner, 'web')->getJson('/app/online-orders/barcode/'.$code)
                ->assertOk()->assertJson(['found' => true, 'color' => 'WHITE-BLUE'])
                ->assertJsonMissingPath('variant_id')->assertJsonPath('product.id', $product->id);
        }
    }

    public function test_the_articles_own_barcode_resolves_to_the_product(): void
    {
        [, $owner, $product] = $this->shopWithArticle();

        $this->actingAs($owner, 'web')->getJson('/app/online-orders/barcode/80001-IXXZ')
            ->assertOk()->assertJson(['found' => true])->assertJsonMissingPath('variant_id')->assertJsonPath('product.id', $product->id);
    }

    public function test_a_zv_code_from_an_unrelated_business_is_not_found(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'online_business');
        $pa = Product::create(['shop_id' => $shopA->id, 'name' => '80001', 'barcode' => '80001-IXXZ', 'cost' => 350, 'price' => 500, 'stock' => 6]);
        $va = ProductVariant::create(['shop_id' => $shopA->id, 'product_id' => $pa->id, 'size' => '40-41', 'color' => 'WHITE-BLUE', 'barcode' => 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', 'stock' => 6]);

        $this->actingAs($ownerB, 'web')->getJson('/app/online-orders/barcode/ZV'.$va->id)
            ->assertStatus(404)->assertJson(['found' => false]);
        $this->actingAs($ownerB, 'web')->getJson('/app/online-orders/barcode/NO-SUCH-CODE')
            ->assertStatus(404)->assertJson(['found' => false]);
    }

    public function test_a_user_without_the_online_business_permission_gets_403(): void
    {
        [$shop, , , $variant] = $this->shopWithArticle();
        $staff = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900001234', 'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->getJson('/app/online-orders/barcode/ZV'.$variant->id)->assertForbidden();
    }
}
