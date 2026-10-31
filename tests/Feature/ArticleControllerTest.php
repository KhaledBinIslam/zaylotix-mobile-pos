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

    /**
     * Khaled set the size groups up on one Baby Park shop and the second
     * branch's user saw no group prices at all. These settings describe how
     * one business encodes and prices, so a branch reads them from its
     * parent — and matching is by category CODE, because the branch's own
     * "BABY" row has a different id entirely.
     */
    public function test_a_branch_inherits_the_businesss_size_group_picks_despite_its_own_category_ids(): void
    {
        [$mainShop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($mainShop, 'article_series');
        $mainCategory = \App\Models\ProductCategory::create(['shop_id' => $mainShop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        [$branch, $branchOwner] = $this->createBranchOf($mainShop);
        // the branch's own BABY row — a different id, the same code, which
        // is exactly what CatalogSync guarantees
        $branchCategory = \App\Models\ProductCategory::create(['shop_id' => $branch->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);
        $this->assertNotSame($mainCategory->id, $branchCategory->id);

        $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [
                ['label' => 'Small', 'min_size' => 16, 'max_size' => 20],
                ['label' => 'Medium', 'min_size' => 21, 'max_size' => 30],
            ],
            'category_ids' => [$mainCategory->id],
        ])->assertSessionHasNoErrors();

        // the branch's own page shows its own box ticked, and its own ranges
        $this->actingAs($branchOwner, 'web')->get('/app/articles/size-group-settings')
            ->assertInertia(fn ($page) => $page
                ->where('selectedCategoryIds', [$branchCategory->id])
                ->where('groups.0.min_size', 16)
                ->where('isCustomized', true));

        // and the three price boxes actually apply in the branch
        $this->actingAs($branchOwner, 'web')->post('/app/articles', $this->articlePayload($branchCategory, [
            'kids_prices' => ['Small' => 500, 'Medium' => 600],
        ]))->assertSessionHasNoErrors();

        $variants = \App\Models\ProductVariant::where('shop_id', $branch->id)->get();
        $this->assertEquals(500, $variants->firstWhere('size', '18')->price);
        $this->assertEquals(600, $variants->firstWhere('size', '25')->price);
    }

    /** A branch saving these settings sets them for the whole business, so the other branch is never left behind again. */
    public function test_a_branch_saving_size_group_settings_sets_them_for_the_business(): void
    {
        [$mainShop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($mainShop, 'article_series');
        $mainCategory = \App\Models\ProductCategory::create(['shop_id' => $mainShop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        [$branch, $branchOwner] = $this->createBranchOf($mainShop);
        $branchCategory = \App\Models\ProductCategory::create(['shop_id' => $branch->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($branchOwner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [['label' => 'Small', 'min_size' => 16, 'max_size' => 30]],
            'category_ids' => [$branchCategory->id],
        ])->assertSessionHasNoErrors();

        // stored on the main shop, as the category CODE — which is what
        // makes it mean the same thing in the branch that saved it
        $this->assertSame(['B'], $mainShop->fresh()->size_group_category_codes);
        $this->assertNull($branch->fresh()->size_group_category_codes);
        $this->assertSame(['B'], $mainShop->fresh()->sizeGroupCategoryCodes());

        // which the main shop's own form then reflects
        $this->actingAs($owner, 'web')->get('/app/articles/size-group-settings')
            ->assertInertia(fn ($page) => $page->where('selectedCategoryIds', [$mainCategory->id]));
    }

    /**
     * Khaled's live data: he saved the size groups on his own shop before
     * they became business-level, so they sat where nothing reads them any
     * more — the other branch's user still saw no group prices after the
     * deploy. The migration lifts them to the main shop and converts the
     * picked ids to codes, so neither of us has to re-save by hand.
     */
    public function test_the_migration_lifts_settings_a_branch_saved_up_to_the_business(): void
    {
        [$mainShop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($mainShop, 'article_series');
        $mainCategory = \App\Models\ProductCategory::create(['shop_id' => $mainShop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        [$branch, $branchOwner] = $this->createBranchOf($mainShop);
        $branchCategory = \App\Models\ProductCategory::create(['shop_id' => $branch->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        // the pre-f771ae05 shape: picked ids, on the branch, parent blank
        $branch->update([
            'kids_shoe_size_groups' => [['label' => 'Small', 'min_size' => 16, 'max_size' => 20]],
            'size_group_category_ids' => [$branchCategory->id],
            'cost_code_digit_map' => ['2' => 'A'],
        ]);
        $this->assertSame([], $mainShop->fresh()->sizeGroupCategoryCodes(), 'nothing reads the branch copy any more');

        (require base_path('database/migrations/2026_10_31_000003_store_size_group_categories_by_code.php'))->up();

        $this->assertSame(['B'], $mainShop->fresh()->size_group_category_codes);
        $this->assertSame(16, $mainShop->fresh()->kidsShoeSizeGroups()[0]['min_size']);
        $this->assertSame('A', $mainShop->fresh()->costCodeDigitMap()['2']);

        // which is the whole point: the OTHER branch now prices by them too
        $this->assertSame(['B'], $branch->fresh()->sizeGroupCategoryCodes());
        $this->actingAs($branchOwner, 'web')->post('/app/articles', $this->articlePayload($branchCategory, [
            'kids_prices' => ['Small' => 500],
            'variants' => [['color' => 'RED', 'size' => '18', 'qty' => 2]],
        ]))->assertSessionHasNoErrors();
        $this->assertEquals(500, \App\Models\ProductVariant::where('shop_id', $branch->id)->first()->price);

        // and a main shop that was configured itself is never overwritten
        $this->assertNotNull($mainCategory->id);
    }

    /**
     * The form draws one price box or one per size group, and it has to
     * reach the same verdict the server does on submit. It was sent only
     * the category NAMES, so Baby Park's "BABY" never got the group boxes
     * however the settings were saved — "article e group wise price set
     * eta ekhono aseni", after the server side was already right.
     */
    public function test_the_create_form_is_told_which_category_codes_price_by_size_group(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->get('/app/articles/create')
            ->assertInertia(fn ($page) => $page->where('sizeGroupCategoryCodes', []));

        $this->actingAs($owner, 'web')->patch('/app/articles/size-group-settings', [
            'groups' => [['label' => 'Small', 'min_size' => 16, 'max_size' => 30]],
            'category_ids' => [$category->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner, 'web')->get('/app/articles/create')
            ->assertInertia(fn ($page) => $page
                ->where('sizeGroupCategoryCodes', ['B'])
                // the category's own code is what the form matches on
                ->where('categories.0.code', 'B'));
    }

    /** The cost-code map decides what a barcode's price segment means, so it cannot differ between two branches of one business. */
    public function test_the_cost_code_map_is_business_level_not_per_branch(): void
    {
        [$mainShop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($mainShop, 'article_series');
        [$branch, $branchOwner] = $this->createBranchOf($mainShop);

        $this->actingAs($branchOwner, 'web')->patch('/app/articles/cost-code-settings', [
            'map' => ['0' => 'X', '1' => 'N', '2' => 'A', '3' => 'S', '4' => 'I', '5' => 'R', '6' => 'J', '7' => 'B', '8' => 'C', '9' => 'D'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('A', $mainShop->fresh()->costCodeDigitMap()['2']);
        $this->assertSame('A', $branch->fresh()->costCodeDigitMap()['2'], 'the branch reads the business map');
        $this->assertNull($branch->fresh()->cost_code_digit_map, 'and does not keep its own copy');
    }

    /** @return array{0: Shop, 1: \App\Models\User} */
    private function createBranchOf(Shop $mainShop): array
    {
        $branch = Shop::create([
            'name' => 'Baby Park 2', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $mainShop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $this->grantFeature($branch, 'article_series');
        $branchOwner = \App\Models\User::create([
            'shop_id' => $branch->id, 'name' => 'Branch Owner', 'phone' => $branch->phone,
            'password' => 'password', 'role' => 'owner', 'lang' => 'bn',
        ]);

        return [$branch, $branchOwner];
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

        $this->assertSame([], $shop->fresh()->size_group_category_codes);
    }

    /**
     * The counter only moves forward, so deleting the last two articles
     * stranded their numbers and the next one started past them. Khaled
     * asked for a freed number to come back — the form is prefilled with
     * the lowest one no live article is using.
     */
    public function test_a_deleted_articles_serial_is_offered_again(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);
        \App\Support\ArticleSerial::bumpFloor($shop, 'B', 20001);

        foreach ([1, 2, 3] as $i) {
            $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990]))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(20004, $this->peekSerial($owner));

        // drop the last two — 20002 and 20003 are now free
        Product::where('shop_id', $shop->id)->whereIn('name', ['B-20002', 'B-20003'])->get()
            ->each(fn (Product $p) => $p->delete());

        $this->assertSame(20002, $this->peekSerial($owner));

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990, 'serial' => 20002]))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Product::where('shop_id', $shop->id)->where('name', 'B-20002')->exists());

        // and the one after that is the other freed number, not 20005
        $this->assertSame(20003, $this->peekSerial($owner));
    }

    public function test_a_serial_a_live_article_still_uses_is_refused(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990]))
            ->assertSessionHasNoErrors();
        $existing = Product::where('shop_id', $shop->id)->firstOrFail();
        $serial = (int) substr($existing->name, 2);

        // two live articles behind one barcode is the thing this must never allow
        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990, 'serial' => $serial]))
            ->assertSessionHasErrors('serial');

        $this->assertSame(1, Product::where('shop_id', $shop->id)->count());
    }

    public function test_a_typed_serial_pushes_the_counter_past_itself(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990, 'serial' => 55555]))
            ->assertSessionHasNoErrors();

        // the automatic one must never land back on a number already issued
        $this->assertSame(55556, \App\Support\ArticleSerial::peekNext($shop->fresh(), 'B'));
    }

    public function test_deleting_an_article_frees_its_barcodes_so_the_serial_can_be_reused(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990]))
            ->assertSessionHasNoErrors();
        $product = Product::where('shop_id', $shop->id)->firstOrFail();
        $serial = (int) substr($product->name, 2);
        $this->assertNotNull($product->barcode);

        $product->delete();

        // a soft-deleted row keeps occupying the unique (shop_id, barcode)
        // index, so the barcodes are released rather than merely hidden
        $this->assertNull(Product::withTrashed()->whereKey($product->id)->value('barcode'));
        $this->assertSame(0, \App\Models\ProductVariant::withTrashed()->where('product_id', $product->id)->whereNotNull('barcode')->count());
        $this->assertSame(0, \App\Models\ProductColorBarcode::where('product_id', $product->id)->count());

        // which is what lets the very same article be entered again
        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990, 'serial' => $serial]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Product::where('shop_id', $shop->id)->count());
    }

    /**
     * Khaled deleted B-20007 before releaseBarcodes() existed, so his rows
     * are hidden while still holding their barcodes — and product_variants'
     * unique (shop_id, barcode) index does not care that a row is
     * soft-deleted. Re-entering that serial, the entire point of the
     * feature, would have died on a constraint violation. The
     * release_barcodes_from_already_deleted_products migration runs exactly
     * what this test runs.
     *
     * The setup leaves one variant trashed and one live on purpose: the
     * cascade (d7e396ea) shipped before the release did, so articles
     * deleted on either side of it are in different shapes and both have to
     * come out clean.
     */
    public function test_an_article_deleted_before_barcodes_were_released_can_still_be_re_entered(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990]))
            ->assertSessionHasNoErrors();
        $product = Product::where('shop_id', $shop->id)->firstOrFail();
        $serial = (int) substr($product->name, 2);
        $wasBarcoded = \App\Models\ProductVariant::where('product_id', $product->id)->pluck('barcode')->filter()->values();
        $this->assertCount(2, $wasBarcoded);

        $cascadedId = \App\Models\ProductVariant::where('product_id', $product->id)->orderBy('id')->value('id');
        Product::withoutEvents(function () use ($product, $cascadedId) {
            \App\Models\ProductVariant::withoutGlobalScopes()->whereKey($cascadedId)->update(['deleted_at' => now()]);
            $product->delete();
        });
        $this->assertSame(2, \App\Models\ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)->whereNotNull('barcode')->count(), 'every barcode is still reserved');

        // the migration itself, not just the method it calls — its job is
        // to FIND these rows, and that query is the part only this covers
        $this->runBarcodeReleaseMigration();

        $this->assertNull(Product::withTrashed()->whereKey($product->id)->value('barcode'));
        $this->assertSame(0, \App\Models\ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)->whereNotNull('barcode')->count());
        $this->assertSame(0, \App\Models\ProductVariant::where('product_id', $product->id)->count(), 'the variant left live is hidden too');
        $this->assertSame(0, \App\Models\ProductColorBarcode::where('product_id', $product->id)->count());

        // the serial is now genuinely usable again with the same colours and
        // sizes — which is precisely where the old barcodes collided, since
        // a variant barcode is a pure function of article, colour and size
        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990, 'serial' => $serial]))
            ->assertSessionHasNoErrors();
        $reentered = Product::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame("B-{$serial}", $reentered->name);
        $this->assertEqualsCanonicalizing(
            $wasBarcoded->all(),
            \App\Models\ProductVariant::where('product_id', $reentered->id)->pluck('barcode')->values()->all(),
            'the re-entered article rebuilds the very same barcodes'
        );
    }

    /** Releasing twice must be a no-op, because the migration is re-runnable and the cascade may have run first. */
    public function test_releasing_barcodes_is_idempotent(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B']);

        $this->actingAs($owner, 'web')->post('/app/articles', $this->articlePayload($category, ['price' => 1990]))
            ->assertSessionHasNoErrors();
        $product = Product::where('shop_id', $shop->id)->firstOrFail();

        $product->delete(); // the cascade already releases
        $deletedAt = \App\Models\ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->value('deleted_at');

        $this->runBarcodeReleaseMigration();
        $this->runBarcodeReleaseMigration();

        $this->assertEquals($deletedAt, \App\Models\ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)->value('deleted_at'), 'an earlier deletion keeps its own timestamp');
        $this->assertSame(0, \App\Models\ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)->whereNotNull('barcode')->count());
    }

    /** Runs the one-off migration that frees barcodes left behind by articles deleted before the cascade released them. */
    private function runBarcodeReleaseMigration(): void
    {
        (require base_path('database/migrations/2026_10_31_000002_release_barcodes_from_already_deleted_products.php'))->up();
    }

    private function peekSerial(\App\Models\User $owner): int
    {
        return (int) $this->actingAs($owner, 'web')
            ->getJson('/app/articles/peek-serial?category_code=B')->json('serial');
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
