<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A bulk import into a pooled shop must land every product and size in
 * every member of the pool with identical stock (the creation hooks'
 * promise) — but batched: the per-row hooks only NOTE the affected
 * products during the import, and one bulk mirror runs at the end
 * (CatalogSync::deferAutoAdopt()). Measured before this existed: a
 * 300-row variant import went from ~1,305 queries (no hooks) to ~8,955
 * (per-row hooks). The ceiling below is "under 2x the old count".
 */
class PooledImportPerformanceTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private const ARTICLES = 50;

    private const SIZES_PER_ARTICLE = 6; // 300 rows

    private const QUERY_CEILING = 2600; // < 2 x 1,305

    private function csv(): string
    {
        $csv = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\n";
        for ($a = 1; $a <= self::ARTICLES; $a++) {
            for ($s = 1; $s <= self::SIZES_PER_ARTICLE; $s++) {
                $size = 35 + $s;
                $csv .= "Article{$a},Article{$a},BC-{$a}-{$size},Shoes,,300,550,,".($a % 5 + 1).",,{$size},Black\n";
            }
        }

        return $csv;
    }

    public function test_a_300_row_import_at_a_branch_lands_everywhere_with_identical_stock_under_the_query_ceiling(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branchA, $ownerA] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$branchB] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        foreach ([$main, $branchA, $branchB] as $shop) {
            $this->grantFeature($shop, 'product_variants');
        }
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($ownerA, 'web')
            ->post(route('app.products.import.store'), ['file' => UploadedFile::fake()->createWithContent('import.csv', $this->csv())])
            ->assertSessionHasNoErrors();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // --- result must be exactly what the per-row hooks produced ---
        foreach ([$main, $branchA, $branchB] as $shop) {
            $this->assertSame(self::ARTICLES, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count(), "shop {$shop->id} holds every article");
            $this->assertSame(self::ARTICLES * self::SIZES_PER_ARTICLE, ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count(), "shop {$shop->id} holds every size");
        }

        $masters = Product::withoutGlobalScopes()->where('shop_id', $main->id)->whereNull('deleted_at')->get()->keyBy('name');
        foreach (Product::withoutGlobalScopes()->whereIn('shop_id', [$branchA->id, $branchB->id])->whereNull('deleted_at')->get() as $copy) {
            $master = $masters->get($copy->name);
            $this->assertNotNull($master);
            $this->assertSame($master->id, $copy->master_product_id, "{$copy->name} at shop {$copy->shop_id} is linked to its master");
            $this->assertEquals((float) $master->stock, (float) $copy->stock, "{$copy->name}: product stock identical");

            $masterSizes = ProductVariant::withoutGlobalScopes()->where('product_id', $master->id)->whereNull('deleted_at')->pluck('stock', 'size');
            $copySizes = ProductVariant::withoutGlobalScopes()->where('product_id', $copy->id)->whereNull('deleted_at')->pluck('stock', 'size');
            $this->assertEquals($masterSizes->sortKeys()->all(), $copySizes->sortKeys()->all(), "{$copy->name}: every size identical at shop {$copy->shop_id}");
        }

        // one article's figures spelled out: Article7 -> stock 3 per size, 18 per product
        $m7 = $masters->get('Article7');
        $this->assertEquals(18, (float) $m7->stock);
        $this->assertEquals(3, (float) ProductVariant::withoutGlobalScopes()->where('product_id', $m7->id)->where('size', '36')->value('stock'));

        // --- and it must stay cheap ---
        fwrite(STDERR, "\nPERF: 300-row pooled import -> {$queries} queries\n");
        $this->assertLessThan(self::QUERY_CEILING, $queries, "300-row pooled import must stay under 2x the pre-hook query count (got {$queries})");
    }

    /** The kill switch still wins inside a batch: hooks off -> the import stays shop-local, nothing is mirrored at the end either. */
    public function test_the_kill_switch_also_disables_the_batched_mirror(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branchA, $ownerA] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        $this->grantFeature($main, 'product_variants');
        $this->grantFeature($branchA, 'product_variants');
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        config(['pos.pool_auto_adopt' => false]);

        $csv = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\nLamp,Lamp,LMP-1,Home,,100,200,,4,,,\n";
        $this->actingAs($ownerA, 'web')
            ->post(route('app.products.import.store'), ['file' => UploadedFile::fake()->createWithContent('import.csv', $csv)])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Lamp')->count());
        $this->assertNull(Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('name', 'Lamp')->value('master_product_id'));
        config(['pos.pool_auto_adopt' => true]);
    }
}
