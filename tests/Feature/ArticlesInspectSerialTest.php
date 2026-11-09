<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park live: picking "BABY (B)" offered serial 80004, not the 20058
 * expected. Read-only diagnostic — pins that it runs cleanly and reports
 * every category row sharing a code, each with its own band.
 */
class ArticlesInspectSerialTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_reports_every_category_sharing_a_code_with_its_own_band(): void
    {
        [$shop] = $this->createShopWithOwner();
        $normal = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 20000]);
        $china = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 80000]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $normal->id, 'name' => 'B-20057', 'name_en' => 'B-20057', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $china->id, 'name' => 'B-80003', 'name_en' => 'B-80003', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->artisan('articles:inspect-serial', ['--shop' => $shop->id, '--code' => 'B'])
            ->expectsOutputToContain((string) $normal->id)
            ->expectsOutputToContain((string) $china->id)
            ->assertSuccessful();
    }

    public function test_an_unknown_code_is_reported_not_errored(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('articles:inspect-serial', ['--shop' => $shop->id, '--code' => 'ZZZ'])->assertSuccessful();
    }

    public function test_requires_a_code(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('articles:inspect-serial', ['--shop' => $shop->id])->assertFailed();
    }
}
