<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\ArticleSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        'cost' => 1300, // encodes to NSXXZ
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
        $this->assertSame('SMS-L-10001-NSXXZ', $product->barcode);
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
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER-36', $variants->firstWhere('size', '36')->barcode);
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
        $this->assertSame('SMS-L-10002-NSXXZ', $product->barcode);
    }

    public function test_an_uploaded_color_photo_is_applied_to_every_variant_of_that_color(): void
    {
        Storage::fake('public');
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->post('/app/articles', $this->payload([
            'photos' => ['SILVER' => UploadedFile::fake()->image('silver.jpg')],
        ]))->assertSessionHasNoErrors();

        $product = Product::where('shop_id', $shop->id)->first();
        $silverVariants = ProductVariant::where('product_id', $product->id)->where('color', 'SILVER')->get();
        $this->assertCount(2, $silverVariants);
        $this->assertTrue($silverVariants->every(fn ($v) => $v->photo_path !== null));
        // KHAKI's cell was zero-qty and never created, so there's nothing to attach a photo to
        $this->assertSame(0, ProductVariant::where('product_id', $product->id)->where('color', 'KHAKI')->count());
    }

    public function test_each_color_in_the_grid_gets_its_own_scannable_color_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->post('/app/articles', $this->payload())->assertSessionHasNoErrors();

        $product = Product::where('shop_id', $shop->id)->first();
        $colorBarcodes = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->pluck('barcode', 'color');
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER', $colorBarcodes['SILVER']);
        // KHAKI's only cell in the payload was zero-qty, so no variant (and no color barcode) exists for it
        $this->assertArrayNotHasKey('KHAKI', $colorBarcodes->toArray());
    }

    public function test_updating_a_color_photo_on_an_existing_article_applies_to_every_matching_variant(): void
    {
        Storage::fake('public');
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);
        $v1 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 2]);
        $v2 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '37', 'color' => 'SILVER', 'stock' => 1]);
        $other = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'BLACK', 'stock' => 1]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/color-photo", [
            'color' => 'SILVER', 'photo' => UploadedFile::fake()->image('silver.jpg'),
        ]);
        $response->assertSessionHasNoErrors();

        $this->assertNotNull($v1->fresh()->photo_path);
        $this->assertNotNull($v2->fresh()->photo_path);
        $this->assertSame($v1->fresh()->photo_path, $v2->fresh()->photo_path);
        $this->assertNull($other->fresh()->photo_path);
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

    /**
     * Size-group pricing used to switch on by matching the category NAME
     * against "Kids' Shoes"/"বাচ্চাদের জুতা". Baby Park — the shop it was
     * built for — calls theirs "BABY", so they never saw the three price
     * boxes. The owner now picks the categories outright.
     */
    public function test_a_picked_category_gets_size_group_prices_whatever_it_is_called(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        // not picked yet: the name doesn't match, so one flat price applies
        // and the 3-price payload is rejected for want of a price
        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, [
            'kids_prices' => ['Small' => 500, 'Medium' => 600, 'Large' => 700],
        ]))->assertSessionHasErrors('price');

        $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [
                ['label' => 'Small', 'min_size' => 16, 'max_size' => 20],
                ['label' => 'Medium', 'min_size' => 21, 'max_size' => 30],
            ],
            'category_ids' => [$category->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, [
            'kids_prices' => ['Small' => 500, 'Medium' => 600],
        ]))->assertSessionHasNoErrors();

        $variants = \App\Models\ProductVariant::where('shop_id', $shop->id)->get();
        $this->assertEquals(500, $variants->firstWhere('size', '18')->price); // Small range
        $this->assertEquals(600, $variants->firstWhere('size', '25')->price); // Medium range
    }

    public function test_picking_no_category_keeps_the_old_name_matching(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $kids = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'বাচ্চাদের জুতা', 'name_en' => "Kids' Shoes", 'code' => 'K']);

        $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [['label' => 'Small', 'min_size' => 16, 'max_size' => 30]],
            'category_ids' => [],
        ])->assertSessionHasNoErrors();

        // nothing picked — the name still decides, exactly as before
        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($kids, [
            'kids_prices' => ['Small' => 500],
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(500, \App\Models\ProductVariant::where('shop_id', $shop->id)->first()->price);
    }

    public function test_a_category_from_another_shop_cannot_be_picked(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$other] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $foreign = \App\Models\ProductCategory::create(['shop_id' => $other->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [['label' => 'Small', 'min_size' => 16, 'max_size' => 30]],
            'category_ids' => [$foreign->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $shop->fresh()->size_group_category_ids);
    }

    /** The article form's own payload shape, with sizes spanning two groups. */
    private function articlePayload(\App\Models\ProductCategory $category, array $overrides = []): array
    {
        return array_merge([
            'manufacturer_code' => 'SMS',
            'category_id' => $category->id,
            'cost' => 1300,
            'variants' => [
                ['color' => 'RED', 'size' => '18', 'qty' => 2],
                ['color' => 'RED', 'size' => '25', 'qty' => 3],
            ],
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ], $overrides);
    }

    /**
     * The defaults only map the digits Khaled's own import data proved
     * (0,1,3,4,5,6) — a cost containing any other digit could not be
     * encoded, and the error told him to fix it in a Settings screen that
     * did not exist. Live block: cost 900 on a new article.
     */
    public function test_cost_code_digit_map_can_be_filled_in_and_unblocks_a_cost_it_could_not_encode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        // 9 is unmapped out of the box, so 900 has no code at all
        $this->assertNull(\App\Support\ArticleBarcode::encodeCost($shop, 900));

        $this->actingAs($owner, 'web')->patch('/app/articles/cost-code-settings', [
            'map' => ['0' => 'X', '1' => 'N', '2' => 'A', '3' => 'S', '4' => 'I', '5' => 'R', '6' => 'J', '7' => 'T', '8' => 'E', '9' => 'P'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('PXXZ', \App\Support\ArticleBarcode::encodeCost($shop->fresh(), 900));
        // and it round-trips, which is the whole point of the map
        $this->assertSame(900, \App\Support\ArticleBarcode::decodeCost($shop->fresh(), 'PXXZ'));
    }

    public function test_cost_code_map_refuses_to_give_two_digits_the_same_letter(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        // X for both 0 and 9 would decode every cost ambiguously
        $this->actingAs($owner, 'web')->patch('/app/articles/cost-code-settings', [
            'map' => ['0' => 'X', '9' => 'X'],
        ])->assertSessionHasErrors('map');

        $this->assertNull($shop->fresh()->cost_code_digit_map);
    }

    public function test_no_digit_may_be_mapped_to_z_because_z_terminates_every_cost_code(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->patch('/app/articles/cost-code-settings', [
            'map' => ['0' => 'X', '9' => 'Z'],
        ])->assertSessionHasErrors('map');

        $this->assertNull($shop->fresh()->cost_code_digit_map);
    }

    public function test_a_digit_left_blank_stays_unmapped_without_blocking_the_rest(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->patch('/app/articles/cost-code-settings', [
            'map' => ['0' => 'X', '1' => 'N', '9' => ''],
        ])->assertSessionHasNoErrors();

        $saved = $shop->fresh()->cost_code_digit_map;
        $this->assertArrayNotHasKey('9', $saved);
        $this->assertSame('NXZ', \App\Support\ArticleBarcode::encodeCost($shop->fresh(), 10));
    }

    public function test_the_cost_code_settings_page_loads_with_the_shops_current_map(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->get('/app/articles/cost-code-settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Articles/CostCodeSettings')
                ->where('costCodeDigitMap.1', 'N')
                ->etc());
    }
}
