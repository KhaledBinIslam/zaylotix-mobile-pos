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
 * Baby Park live bug ("exchange e scanner product not found hocche"):
 * ReturnController::barcodeLookup() — the exchange flow's own "scan the new
 * item" picker — never had any of PosController::barcode()'s three-tier/
 * ZV-ZC/cross-branch resolution, just a bare exact-string match. Both now
 * share App\Support\BarcodeResolver, so these mirror PosBarcodeEndpointTest's
 * cases for the one endpoint that was missing them.
 */
class ReturnBarcodeLookupTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_an_exact_variant_barcode_resolves_with_a_variant_id(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/barcode/SMS-L-10001-NSXX-SILVER-36');

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id])
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_a_short_zv_code_resolves_to_its_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/barcode/ZV'.$variant->id);

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id]);
    }

    public function test_a_short_zv_code_printed_at_the_main_shop_still_resolves_at_a_sibling_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branch = Shop::create([
            'name' => 'Branch', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);
        $branchOwner = User::create(['shop_id' => $branch->id, 'name' => 'Branch Staff', 'phone' => $branch->phone, 'password' => 'password', 'role' => 'owner', 'lang' => 'bn']);
        $this->grantFeature($branch, 'returns');

        $mp = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mp->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'L-10001', 'master_product_id' => $mp->id, 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $branchVariant = ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $bp->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        // scanning the label printed FROM THE MAIN SHOP's own variant id, while exchanging at the branch
        $response = $this->actingAs($branchOwner, 'web')->getJson('/app/returns/barcode/ZV'.$mainVariant->id);

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $branchVariant->id]);
    }

    public function test_a_color_barcode_resolves_with_the_color_but_no_variant_id(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/barcode/SMS-L-10001-NSXX-SILVER');

        $response->assertOk()->assertJson(['found' => true, 'color' => 'SILVER'])
            ->assertJsonMissingPath('variant_id')->assertJsonPath('product.id', $product->id);
    }

    public function test_an_unknown_barcode_is_not_found(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/barcode/NO-SUCH-CODE');

        $response->assertStatus(404)->assertJson(['found' => false]);
    }

    public function test_tenant_isolation_a_short_zv_code_never_resolves_across_unrelated_shops(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'returns');
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variantA = ProductVariant::create(['shop_id' => $shopA->id, 'product_id' => $productA->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($ownerB, 'web')->getJson('/app/returns/barcode/ZV'.$variantA->id);

        $response->assertStatus(404);
    }
}
