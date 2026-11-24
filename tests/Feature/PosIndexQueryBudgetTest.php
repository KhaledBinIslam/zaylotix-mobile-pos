<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Opening POS must cost a handful of queries, not one per size. Baby Park
 * live, 2026-11-24: 2,002 sizes, source_lines off -> ~2,200 queries and
 * 1.3 s per open, because PosController::hideCost() touched
 * `$variant->sources` (a null-safe call still lazy-loads the relation)
 * on every size just to hide a cost on rows that were never loaded.
 */
class PosIndexQueryBudgetTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function catalogue(int $articles, int $sizes, bool $withSources): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        if ($withSources) {
            $this->grantFeature($shop, 'suppliers');
            $this->grantFeature($shop, 'source_lines');
        }
        Tenancy::set($shop->id);
        $supplier = $withSources ? Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]) : null;

        for ($a = 1; $a <= $articles; $a++) {
            $p = Product::create(['shop_id' => $shop->id, 'name' => "A-{$a}", 'cost' => 100, 'price' => 200, 'stock' => $sizes]);
            for ($s = 1; $s <= $sizes; $s++) {
                $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => (string) (35 + $s), 'color' => 'BLACK', 'stock' => 1, 'cost' => 110]);
                if ($withSources) {
                    ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $supplier->id, 'cost' => 110, 'price' => 200, 'stock' => 1]);
                }
            }
        }

        return [$shop, $owner];
    }

    public function test_opening_pos_without_source_lines_never_queries_per_size(): void
    {
        [, $owner] = $this->catalogue(articles: 20, sizes: 10, withSources: false); // 200 sizes

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($owner, 'web')->get('/app/pos');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(60, $queries, "POS open must not scale with the number of sizes (got {$queries} queries for 200 sizes)");

        // still no cost anywhere in what the cashier receives
        $response->assertInertia(fn ($page) => $page
            ->has('products', 20)
            ->missing('products.0.cost')
            ->missing('products.0.variants.0.cost'));
    }

    public function test_opening_pos_with_source_lines_eager_loads_them_and_still_hides_every_cost(): void
    {
        [, $owner] = $this->catalogue(articles: 10, sizes: 6, withSources: true); // 60 sizes, 60 lots

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($owner, 'web')->get('/app/pos');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(60, $queries, "eager-loaded lots must not add a query per size (got {$queries})");
        $response->assertInertia(fn ($page) => $page
            ->has('products', 10)
            ->has('products.0.variants.0.sources', 1)
            ->missing('products.0.variants.0.cost')
            ->missing('products.0.variants.0.sources.0.cost'));
    }
}
