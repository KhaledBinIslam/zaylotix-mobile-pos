<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park live: "JUMP-টেপবড়-70001..." — a category code typed in
 * Bengali (createArticle() had no ASCII check at the time) made the whole
 * article unscannable from the day it was created, with no settings
 * screen to fix it afterward (unlike a colour's own short code). This
 * command is that fix, from the shell.
 */
class ArticlesFixCategoryCodeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aBrokenArticle(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Tape Big', 'name_en' => 'Tape Big', 'code' => 'টেপবড়']);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Jump Industries', 'short_code' => 'JUMP']);
        $product = Product::create([
            'shop_id' => $shop->id, 'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'name' => 'টেপবড়-70001', 'name_en' => 'টেপবড়-70001',
            'barcode' => 'JUMP-টেপবড়-70001-NSXXZ',
            'cost' => 1300, 'price' => 1990, 'stock' => 0,
        ]);

        return [$shop, $owner, $category, $product];
    }

    public function test_renames_the_category_and_rebuilds_the_articles_barcode(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'tpb',
        ])->assertSuccessful();

        $this->assertSame('TPB', $category->fresh()->code);
        $this->assertSame('TPB-70001', $product->fresh()->name);
        $this->assertSame('TPB-70001', $product->fresh()->name_en);
        $this->assertSame('JUMP-TPB-70001-NSXXZ', $product->fresh()->barcode);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'TPB', '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('টেপবড়', $category->fresh()->code);
        $this->assertSame('টেপবড়-70001', $product->fresh()->name);
        $this->assertSame('JUMP-টেপবড়-70001-NSXXZ', $product->fresh()->barcode);
    }

    public function test_refuses_a_non_ascii_new_code(): void
    {
        [$shop, , $category] = $this->aBrokenArticle();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'ফিতা',
        ])->assertFailed();

        $this->assertSame('টেপবড়', $category->fresh()->code);
    }

    public function test_refuses_a_new_code_already_used_by_another_category(): void
    {
        [$shop, , $category] = $this->aBrokenArticle();
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'name_en' => 'Ladies', 'code' => 'L']);

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'L',
        ])->assertSuccessful(); // the command reports per-shop and keeps going, never a hard failure

        $this->assertSame('টেপবড়', $category->fresh()->code); // but THIS shop's rename never happened
    }

    public function test_an_unrelated_article_under_the_same_category_is_untouched_if_its_name_does_not_match(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();
        // a plain product filed under the same category by name only, not an article
        $other = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Random Item', 'name_en' => 'Random Item', 'cost' => 100, 'price' => 150, 'stock' => 5]);

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'TPB',
        ])->assertSuccessful();

        $this->assertSame('Random Item', $other->fresh()->name);
        $this->assertSame('TPB-70001', $product->fresh()->name);
    }

    /**
     * Baby Park live: the exact bug hit on deploy — a SECOND category
     * ("School", no articles of its own) also carried code "টেপবড়", and
     * the command picked that one (0 articles found) instead of the real
     * article's own category row. Both must be renamed, and the real
     * article's barcode fixed, in one run.
     */
    public function test_renames_every_category_sharing_the_old_code_not_just_the_first_one_found(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();
        $unrelated = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'School', 'name_en' => 'School', 'code' => 'টেপবড়']);

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'TPB',
        ])->assertSuccessful();

        $this->assertSame('TPB', $category->fresh()->code);
        $this->assertSame('TPB', $unrelated->fresh()->code);
        $this->assertSame('TPB-70001', $product->fresh()->name);
        $this->assertSame('JUMP-TPB-70001-NSXXZ', $product->fresh()->barcode);
    }

    /**
     * Baby Park live: retyping "টেপবড়" over SSH kept reporting a 0-article
     * "School" category instead of the real one — the typed text didn't
     * byte-match the DB (invisible Unicode normalization difference).
     * --by-product finds the category via a plain-ASCII product name
     * substring instead, reading the category's own code straight from the
     * DB, so retyping Bengali is never required.
     */
    public function test_by_product_locates_the_category_without_retyping_its_code(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--by-product' => '70001', '--new-code' => 'TPB',
        ])->assertSuccessful();

        $this->assertSame('TPB', $category->fresh()->code);
        $this->assertSame('TPB-70001', $product->fresh()->name);
        $this->assertSame('JUMP-TPB-70001-NSXXZ', $product->fresh()->barcode);
    }

    public function test_by_product_with_no_matching_name_is_reported_not_errored(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--by-product' => 'NOPE12345', '--new-code' => 'TPB',
        ])->assertSuccessful();
    }

    /**
     * Baby Park live: --by-product="70001" also matched an article whose
     * category had ALREADY been fixed to an English code, dragging it
     * along and relabeling it yet again — a branch category was observed
     * churning TPB → FITA → TCHOT → DAMI across four separate runs. Only a
     * still-Bengali-coded category is a real target.
     */
    public function test_by_product_skips_a_category_whose_code_is_already_ascii(): void
    {
        [$shop] = $this->createShopWithOwner();
        $fixed = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Fixed', 'name_en' => 'Fixed', 'code' => 'TPB']);
        $broken = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Broken', 'name_en' => 'Broken', 'code' => 'ফিতা']);
        $fixedProduct = Product::create(['shop_id' => $shop->id, 'category_id' => $fixed->id, 'name' => 'TPB-70009', 'name_en' => 'TPB-70009', 'barcode' => 'JUMP-TPB-70009-NSXXZ', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        $brokenProduct = Product::create(['shop_id' => $shop->id, 'category_id' => $broken->id, 'name' => 'ফিতা-70009', 'name_en' => 'ফিতা-70009', 'barcode' => 'JUMP-ফিতা-70009-NSXXZ', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--by-product' => '70009', '--new-code' => 'FITA',
        ])->assertSuccessful();

        $this->assertSame('TPB', $fixed->fresh()->code);
        $this->assertSame('TPB-70009', $fixedProduct->fresh()->name);
        $this->assertSame('FITA', $broken->fresh()->code);
        $this->assertSame('FITA-70009', $brokenProduct->fresh()->name);
    }

    public function test_requires_old_code_or_by_product(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--new-code' => 'TPB',
        ])->assertFailed();
    }

    /**
     * Baby Park live: even --by-product="70001" kept missing the real
     * article — the serial itself was most likely typed in Bengali-script
     * digits, not ASCII ones, so no text substring from a keyboard could
     * ever match it. An exact category id needs no text matched at all.
     */
    public function test_category_id_fixes_the_exact_row_with_no_text_matching(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();

        $this->artisan('articles:fix-category-code', [
            '--category-id' => $category->id, '--new-code' => 'TPB',
        ])->assertSuccessful();

        $this->assertSame('TPB', $category->fresh()->code);
        $this->assertSame('TPB-70001', $product->fresh()->name);
        $this->assertSame('JUMP-TPB-70001-NSXXZ', $product->fresh()->barcode);
    }

    public function test_category_id_reports_an_unknown_id_not_errors_silently(): void
    {
        $this->artisan('articles:fix-category-code', [
            '--category-id' => 999999, '--new-code' => 'TPB',
        ])->assertFailed();
    }

    public function test_reaches_a_branch_too(): void
    {
        [$shop, , $category, $product] = $this->aBrokenArticle();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $shop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $branchCategory = ProductCategory::create(['shop_id' => $branch->id, 'name' => 'Tape Big', 'name_en' => 'Tape Big', 'code' => 'টেপবড়']);
        $branchProduct = Product::create([
            'shop_id' => $branch->id, 'category_id' => $branchCategory->id,
            'name' => 'টেপবড়-70001', 'name_en' => 'টেপবড়-70001',
            'barcode' => 'JUMP-টেপবড়-70001-NSXXZ', 'master_product_id' => $product->id,
            'cost' => 1300, 'price' => 1990, 'stock' => 0,
        ]);

        $this->artisan('articles:fix-category-code', [
            '--shop' => $shop->id, '--old-code' => 'টেপবড়', '--new-code' => 'TPB',
        ])->assertSuccessful();

        $this->assertSame('TPB-70001', $branchProduct->fresh()->name);
        $this->assertSame('TPB', $branchCategory->fresh()->code);
    }
}
