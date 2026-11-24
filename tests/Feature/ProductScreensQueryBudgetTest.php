<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Screens that load the whole product list with its sizes must cost a flat
 * handful of queries however big the catalogue is. POS once ran ~2,200
 * queries per open at Baby Park (one lazy load per size, hidden inside a
 * null-safe call) and nobody noticed until the owner said "the system is
 * slow". This runs each such screen against the same shop at two catalogue
 * sizes: if the query count grows with the number of sizes, it fails.
 */
class ProductScreensQueryBudgetTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function addArticles(int $shopId, int $from, int $to): void
    {
        for ($a = $from; $a <= $to; $a++) {
            $p = Product::create(['shop_id' => $shopId, 'name' => "A-{$a}", 'barcode' => "BC-{$a}", 'cost' => 100, 'price' => 200, 'stock' => 4]);
            for ($s = 1; $s <= 4; $s++) {
                ProductVariant::create(['shop_id' => $shopId, 'product_id' => $p->id, 'size' => (string) (35 + $s), 'color' => 'BLACK', 'barcode' => "BC-{$a}-{$s}", 'stock' => 1]);
            }
        }
    }

    private function queriesFor($owner, string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner, 'web')->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_product_list_screens_do_not_query_per_size(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['product_variants', 'online_business', 'returns'] as $f) {
            $this->grantFeature($shop, $f);
        }

        $screens = ['/app/pos', '/app/stock', '/app/online-orders/create'];

        $this->addArticles($shop->id, 1, 5);                       //  20 sizes
        $small = [];
        foreach ($screens as $url) {
            $small[$url] = $this->queriesFor($owner, $url);
        }

        $this->addArticles($shop->id, 6, 30);                      // 120 sizes (6x)
        foreach ($screens as $url) {
            $big = $this->queriesFor($owner, $url);
            $this->assertLessThanOrEqual(
                $small[$url] + 6,
                $big,
                "{$url}: {$small[$url]} queries at 20 sizes but {$big} at 120 — it is querying per size/product"
            );
        }
    }
}
