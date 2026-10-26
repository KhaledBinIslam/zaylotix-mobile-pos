<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\ArticleSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * docs/ARTICLE_SERIES_PLAN.md §7 — the one-screen new article entry form's
 * backend. Gated behind the `article_series` feature flag.
 */
class ArticleControllerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private array $basePayload = [
        'manufacturer_code' => 'SMS',
        'new_category_name' => "Women's Shoes",
        'category_code' => 'L',
        'cost' => 1300, // encodes to NSXX
        'price' => 1990,
        'colorsText' => 'SILVER, KHAKI',
        'sizesText' => '36-37',
    ];

    private function payload(array $overrides = []): array
    {
        return array_merge($this->basePayload, [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'variants' => [
                ['color' => 'SILVER', 'size' => '36', 'qty' => 2],
                ['color' => 'SILVER', 'size' => '37', 'qty' => 1],
                ['color' => 'KHAKI', 'size' => '36', 'qty' => 0], // zero-qty cells are skipped, not created
            ],
        ], $overrides);
    }

    public function test_the_create_page_is_blocked_without_the_feature_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/app/articles/create')->assertForbidden();
    }

    public function test_creates_a_new_category_and_supplier_and_builds_the_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->post('/app/articles', $this->payload());
        $response->assertSessionHasNoErrors();

        $product = Product::where('shop_id', $shop->id)->first();
        $this->assertNotNull($product);
        $this->assertSame('SMS-L-10001-NSXX', $product->barcode);
        $this->assertSame('L-10001', $product->name_en);
        $this->assertEquals(1300, $product->cost);
        $this->assertEquals(1990, $product->price);

        $supplier = Supplier::where('shop_id', $shop->id)->where('short_code', 'SMS')->first();
        $this->assertNotNull($supplier);
        $this->assertSame($supplier->id, $product->supplier_id);

        $category = ProductCategory::where('shop_id', $shop->id)->first();
        $this->assertSame('L', $category->code);

        $variants = ProductVariant::where('product_id', $product->id)->get();
        $this->assertCount(2, $variants); // the zero-qty KHAKI/36 cell was skipped
        $this->assertEquals(3, $product->fresh()->stock);
        $this->assertSame('SMS-L-10001-NSXX-SILVER-36', $variants->firstWhere('size', '36')->barcode);
    }

    public function test_reuses_an_existing_category_and_its_code_instead_of_duplicating(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'মেয়েদের জুতা', 'name_en' => "Women's Shoes", 'code' => 'L']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->payload([
            'category_id' => $category->id, 'new_category_name' => null, 'category_code' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, ProductCategory::where('shop_id', $shop->id)->count());
    }

    public function test_kids_shoes_get_a_price_per_size_group_not_one_flat_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->post('/app/articles', $this->payload([
            'new_category_name' => "Kids' Shoes", 'category_code' => 'K', 'price' => null,
            'kids_prices' => ['Small' => 500, 'Medium' => 550, 'Large' => 600],
            'colorsText' => 'BLACK', 'sizesText' => '24-33',
            'variants' => [
                ['color' => 'BLACK', 'size' => '24', 'qty' => 1], // Small (21-28)
                ['color' => 'BLACK', 'size' => '30', 'qty' => 1], // Medium (29-32)
                ['color' => 'BLACK', 'size' => '33', 'qty' => 1], // Large (33-37)
            ],
        ]));
        $response->assertSessionHasNoErrors();

        $product = Product::where('shop_id', $shop->id)->first();
        $variants = ProductVariant::where('product_id', $product->id)->get();
        $this->assertEquals(500, $variants->firstWhere('size', '24')->price);
        $this->assertEquals(550, $variants->firstWhere('size', '30')->price);
        $this->assertEquals(600, $variants->firstWhere('size', '33')->price);
        // Product.price falls back to the Medium group's price
        $this->assertEquals(550, $product->price);
    }

    public function test_an_unmapped_cost_digit_is_rejected_not_guessed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->post('/app/articles', $this->payload(['cost' => 1700])); // '7' isn't mapped by default

        $response->assertSessionHasErrors('cost');
        $this->assertSame(0, Product::where('shop_id', $shop->id)->count());
    }

    public function test_resubmitting_the_same_client_uuid_does_not_create_a_second_article(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $payload = $this->payload();

        $this->actingAs($owner, 'web')->post('/app/articles', $payload)->assertSessionHasNoErrors();
        $this->actingAs($owner, 'web')->post('/app/articles', $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Product::where('shop_id', $shop->id)->count());
    }

    public function test_tenant_isolation_one_shops_submit_never_touches_another_shops_rows(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'article_series');

        $this->actingAs($ownerA, 'web')->post('/app/articles', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, Product::where('shop_id', $shopA->id)->count());
        $this->assertSame(0, Product::where('shop_id', $shopB->id)->count());
    }

    public function test_a_branch_shares_the_main_shops_serial_counter_when_creating_an_article(): void
    {
        [$mainShop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($mainShop, 'article_series');
        ArticleSerial::next($mainShop, 'L'); // pre-reserve 10001 so the branch's article must land on 10002

        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $mainShop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $this->grantFeature($branch, 'article_series');
        $branchOwner = \App\Models\User::create(['shop_id' => $branch->id, 'name' => 'Branch Owner', 'phone' => $branch->phone, 'password' => 'password', 'role' => 'owner', 'lang' => 'bn']);

        $this->actingAs($branchOwner, 'web')->post('/app/articles', $this->payload())->assertSessionHasNoErrors();

        $product = Product::where('shop_id', $branch->id)->first();
        $this->assertSame('SMS-L-10002-NSXX', $product->barcode);
    }

    public function test_size_group_settings_can_be_customized_and_rejects_overlapping_ranges(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $overlap = $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [
                ['label' => 'Small', 'min_size' => 21, 'max_size' => 29],
                ['label' => 'Medium', 'min_size' => 28, 'max_size' => 32],
            ],
        ]);
        $overlap->assertSessionHasErrors('groups');

        $ok = $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [
                ['label' => 'Small', 'min_size' => 20, 'max_size' => 24],
                ['label' => 'Medium', 'min_size' => 25, 'max_size' => 29],
                ['label' => 'Large', 'min_size' => 30, 'max_size' => 34],
            ],
        ]);
        $ok->assertSessionHasNoErrors();
        $this->assertSame(20, $shop->fresh()->kids_shoe_size_groups[0]['min_size']);
    }
}
