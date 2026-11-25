<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Support\ArticleSerial;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A category's own starting number.
 *
 * Khaled reads the kind of shoe off the article number — "slipers holei
 * 90000 serial a porbe / LAdies hole 10000 / babyyy 20000 / Pumpi 50000 /
 * ladies cat 30000" — and in his shop Slippers and Ladies share the code
 * L, so the band has to belong to the CATEGORY while the code goes on
 * guaranteeing unique barcodes.
 */
class ArticleSerialBandTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function article(Shop $shop, ProductCategory $category, int $serial): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => "{$category->code}-{$serial}",
            'name_en' => "{$category->code}-{$serial}",
            'cost' => 100,
            'price' => 200,
            'stock' => 1,
        ]);
    }

    private function branchOf(Shop $main): Shop
    {
        return Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
    }

    public function test_a_category_without_a_band_behaves_exactly_as_before(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::next($shop, 'L'));
        $this->assertSame(10002, ArticleSerial::next($shop, 'L'));
        $this->assertSame(10001, ArticleSerial::peekNext($shop, 'G'));
    }

    public function test_a_band_starts_the_numbers_where_the_owner_put_them(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(90000, ArticleSerial::next($shop, 'L', 90000));
        $this->assertSame(90001, ArticleSerial::next($shop, 'L', 90000));
        $this->assertSame(90002, ArticleSerial::peekNext($shop, 'L', 90000));
    }

    public function test_two_categories_on_one_code_keep_separate_bands(): void
    {
        [$shop] = $this->createShopWithOwner();

        // Slippers and Ladies are both "L" here — one from 90000, one from
        // 10001 — and neither may drag the other along.
        $this->assertSame(10001, ArticleSerial::next($shop, 'L', 10001));
        $this->assertSame(90000, ArticleSerial::next($shop, 'L', 90000));
        $this->assertSame(10002, ArticleSerial::next($shop, 'L', 10001));
        $this->assertSame(90001, ArticleSerial::next($shop, 'L', 90000));
    }

    public function test_next_free_never_suggests_a_number_below_the_band(): void
    {
        [$shop] = $this->createShopWithOwner();
        $ladies = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'code' => 'L']);

        foreach ([10001, 10002, 10003] as $serial) {
            $this->article($shop, $ladies, $serial);
        }
        ArticleSerial::bumpFloor($shop, 'L', 10004);

        // a Slippers entry on the SAME code must still be offered 90000
        $this->assertSame(90000, ArticleSerial::nextFree($shop, 'L', 90000));
        // while Ladies, band-less, keeps the old gap-filling answer
        $this->assertSame(10004, ArticleSerial::nextFree($shop, 'L'));
    }

    public function test_a_gap_inside_the_band_comes_back(): void
    {
        [$shop] = $this->createShopWithOwner();
        $slippers = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Slippers', 'code' => 'L', 'serial_start' => 90000]);

        $made = [];
        foreach ([90000, 90001, 90002] as $serial) {
            $made[$serial] = $this->article($shop, $slippers, $serial);
        }
        ArticleSerial::bumpFloor($shop, 'L', 90003, 90000);

        $this->assertSame(90003, ArticleSerial::nextFree($shop, 'L', 90000));

        // held from creation rather than looked up: no user is acting here,
        // so TenantScope resolves to shop_id 0 and a plain query finds
        // nothing (the same trap ArticleSerial's own docblock warns about)
        $made[90001]->delete();

        $this->assertSame(90001, ArticleSerial::nextFree($shop, 'L', 90000));
    }

    public function test_an_article_created_in_a_new_banded_category_lands_in_its_band(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        // the shared code is already well up its own range, which is
        // exactly the situation that handed Khaled 10041 for SLIPERS
        $ladies = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'code' => 'L']);
        $this->article($shop, $ladies, 10001);
        ArticleSerial::bumpFloor($shop, 'L', 10041);

        $this->actingAs($owner, 'web')->post('/app/articles', [
            'client_uuid' => (string) Str::uuid(),
            'new_category_name' => 'SLIPERS',
            'category_code' => 'L',
            'serial_start' => 90000,
            'manufacturer_code' => 'H',
            'cost' => 1300,
            'price' => 1990,
            'colorsText' => 'BLACK',
            'sizesText' => '38',
            'variants' => [['color' => 'BLACK', 'size' => '38', 'qty' => 2]],
        ])->assertSessionHasNoErrors();

        $category = ProductCategory::where('shop_id', $shop->id)->where('name', 'SLIPERS')->first();
        $this->assertSame(90000, $category->serial_start);
        $this->assertDatabaseHas('products', ['shop_id' => $shop->id, 'name' => 'L-90000']);
    }

    public function test_the_preview_answers_from_the_band_being_typed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $ladies = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'code' => 'L']);
        $this->article($shop, $ladies, 10001);
        ArticleSerial::bumpFloor($shop, 'L', 10041);

        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_code' => 'L']))
            ->assertOk()->assertJson(['serial' => 10002]);

        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_code' => 'L', 'serial_start' => 90000]))
            ->assertOk()->assertJson(['serial' => 90000]);
    }

    /**
     * Baby Park live, 2026-11-24: BABY's band starts at 20000 but its first
     * article was B-20001, so 20000 was never used. The preview took that
     * never-used number for a free gap and suggested B-20000 while the series
     * stood at B-20066 — "it should be 20067 next".
     */
    public function test_the_preview_continues_the_series_instead_of_suggesting_the_unused_band_start(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $baby = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'code' => 'B', 'serial_start' => 20000]);

        for ($n = 20001; $n <= 20066; $n++) {
            $this->article($shop, $baby, $n);
        }
        ArticleSerial::bumpFloor($shop, 'B', 20067, 20000);

        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_id' => $baby->id]))
            ->assertOk()->assertJson(['serial' => 20067]);
    }

    /**
     * Real production shapes (Baby Park, checked 2026-11-25): the suggestion
     * must never be a number a live article already carries.
     */
    public function test_the_suggestion_skips_a_number_another_band_of_the_same_code_already_uses(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        // S has several bands, each with its own counter: band 80001's counter
        // says "next 80002" while S-80002 is live (it belongs to band 80002)
        $a = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'china A', 'code' => 'S', 'serial_start' => 80001]);
        $b = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'china B', 'code' => 'S', 'serial_start' => 80002]);
        $this->article($shop, $a, 80001);
        $this->article($shop, $b, 80002);
        $this->article($shop, $b, 80003);
        ArticleSerial::bumpFloor($shop, 'S', 80002, 80001);   // band 80001 counter: next 80002 (live!)

        $this->assertSame(80004, ArticleSerial::nextFree($shop, 'S', 80001));
        $this->assertNotContains(ArticleSerial::nextFree($shop, 'S', 80001), [80001, 80002, 80003]);
    }

    public function test_the_suggestion_is_not_a_live_number_when_the_counter_row_is_missing(): void
    {
        [$shop] = $this->createShopWithOwner();
        // a category whose code was renamed: its articles exist under DAMI but
        // the counter row was created under the old code, so DAMI has none
        $dami = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'School', 'code' => 'DAMI', 'serial_start' => 70004]);
        $this->article($shop, $dami, 70004);

        $this->assertSame(70005, ArticleSerial::nextFree($shop, 'DAMI', 70004));
    }

    /** ...and a number the series really went past and then freed is still offered first, as before. */
    public function test_a_freed_number_inside_the_series_is_still_reused(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $baby = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'BABY', 'code' => 'B', 'serial_start' => 20000]);

        foreach ([20001, 20002, 20003, 20004] as $n) {
            $this->article($shop, $baby, $n);
        }
        Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'B-20003')->first()->delete();
        ArticleSerial::bumpFloor($shop, 'B', 20005, 20000);

        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_id' => $baby->id]))
            ->assertOk()->assertJson(['serial' => 20003]);
    }

    /**
     * Baby Park (4 categories) and SEAMAN (all 98) had categories with no code,
     * and a category with no code gave no serial. The form now pre-fills a
     * suggested code, so the preview must answer for a SAVED category that has
     * no code yet when the code rides along with the category id.
     */
    public function test_the_preview_gives_a_serial_for_a_saved_category_that_has_no_code_yet(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $sandals = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'স্যান্ডেল', 'name_en' => 'Sandals', 'code' => null]);

        // before: the category id alone -> no code anywhere -> no serial
        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_id' => $sandals->id]))
            ->assertOk()->assertJson(['serial' => null]);

        // now: the form sends the suggested/typed code alongside the id
        $this->actingAs($owner, 'web')
            ->getJson(route('app.articles.peekSerial', ['category_id' => $sandals->id, 'category_code' => 'SAN']))
            ->assertOk()->assertJson(['serial' => 10001]);
    }

    public function test_the_settings_page_saves_a_band_and_pushes_the_counter_past_what_is_already_there(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $baby = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'code' => 'B']);

        // articles already sitting in the band the owner is about to pick
        foreach ([20001, 20002] as $serial) {
            $this->article($shop, $baby, $serial);
        }

        $this->actingAs($owner, 'web')
            ->patch(route('app.articles.serialSettings.update'), ['starts' => [$baby->id => 20000]])
            ->assertSessionHasNoErrors();

        $this->assertSame(20000, $baby->fresh()->serial_start);
        // The series begins at 20001 (where its first article is), so the
        // band's nominal start 20000 is NOT a gap — offering it was the bug
        // Baby Park hit at B-20066 (the form suggested B-20000). Both the
        // suggestion and the counter now point past what is already there.
        $this->assertSame(20003, ArticleSerial::nextFree($shop, 'B', 20000));
        $this->assertSame(20003, ArticleSerial::peekNext($shop, 'B', 20000));
    }

    public function test_clearing_a_band_returns_the_category_to_the_default(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $pumpi = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Pumpi', 'code' => 'P', 'serial_start' => 50000]);

        $this->actingAs($owner, 'web')
            ->patch(route('app.articles.serialSettings.update'), ['starts' => [$pumpi->id => null]])
            ->assertSessionHasNoErrors();

        $this->assertNull($pumpi->fresh()->serial_start);
    }

    public function test_the_settings_page_lists_each_category_with_its_own_band(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Slippers', 'code' => 'L', 'serial_start' => 90000]);
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'code' => 'L', 'serial_start' => 10000]);

        $this->actingAs($owner, 'web')->get(route('app.articles.serialSettings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Articles/SerialSettings')
                ->where('rows.0.name', 'Ladies')
                ->where('rows.0.serial_start', 10000)
                ->where('rows.0.next_free', 10000)
                ->where('rows.1.name', 'Slippers')
                ->where('rows.1.serial_start', 90000)
                ->where('rows.1.next_free', 90000)
                ->etc());
    }

    public function test_a_branch_inherits_the_band_from_the_main_shop(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branch = $this->branchOf($main);
        ProductCategory::create(['shop_id' => $main->id, 'name' => 'Slippers', 'code' => 'L', 'serial_start' => 90000]);

        CatalogSync::syncBusiness($main);

        $copy = ProductCategory::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Slippers')->first();
        $this->assertNotNull($copy);
        $this->assertSame(90000, $copy->serial_start);
    }
}
