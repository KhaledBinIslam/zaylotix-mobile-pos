<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\ShopResourceUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live: "ami ekta system cai shop wise koto percent storage/cpu/ram
 * use korteche" — followed by confusion over SystemHealthController's
 * disk_free_space() figure being the WHOLE shared host, not this app's
 * own usage ("ami 20gb space khai pechi?" over a 70%-of-21TB number).
 * ShopResourceUsage is the honest replacement: real per-shop storage
 * (database share by row count, files exactly), no fake per-shop CPU/RAM.
 */
class ShopResourceUsageTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'usage-admin@test.com', 'password' => 'password']);
    }

    public function test_file_bytes_are_split_between_shops_by_their_own_real_photos(): void
    {
        Storage::fake('public');
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        // file_mb is rounded to 2 decimals for display — real-sized photos
        // (hundreds of KB+) so that rounding never erases the signal, the
        // way a 1000-byte fixture would (0.00095 MB rounds to 0.0 either way)
        Storage::disk('public')->put('product-photos/a.jpg', str_repeat('a', 500_000));
        Storage::disk('public')->put('product-photos/b.jpg', str_repeat('b', 1_500_000));
        Product::create(['shop_id' => $shopA->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 1, 'photo_path' => 'product-photos/a.jpg']);
        Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 1, 'photo_path' => 'product-photos/b.jpg']);

        $result = ShopResourceUsage::perShop();
        $rows = collect($result['shops'])->keyBy('id');

        // B's file is really 3x A's, not an even split
        $this->assertGreaterThan($rows[$shopA->id]['file_mb'], $rows[$shopB->id]['file_mb']);
        $this->assertEqualsWithDelta(500_000 / 1048576, $rows[$shopA->id]['file_mb'], 0.01);
        $this->assertEqualsWithDelta(1_500_000 / 1048576, $rows[$shopB->id]['file_mb'], 0.01);
    }

    public function test_a_shop_with_no_data_at_all_reports_zero_not_an_error(): void
    {
        [$shop] = $this->createShopWithOwner();

        $result = ShopResourceUsage::perShop();
        $row = collect($result['shops'])->firstWhere('id', $shop->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['product_count']);
        $this->assertSame(0, $row['sales_count']);
        $this->assertIsFloat($row['total_mb']);
    }

    public function test_activity_counts_reflect_real_rows_per_shop(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => 'A1', 'cost' => 1, 'price' => 2, 'stock' => 1]);
        Product::create(['shop_id' => $shopA->id, 'name' => 'A2', 'cost' => 1, 'price' => 2, 'stock' => 1]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'B1', 'cost' => 1, 'price' => 2, 'stock' => 1]);
        User::create(['shop_id' => $shopA->id, 'name' => 'Staff', 'phone' => '01700000111', 'password' => 'password', 'role' => 'staff', 'lang' => 'bn']);

        $result = ShopResourceUsage::perShop();
        $rows = collect($result['shops'])->keyBy('id');

        $this->assertSame(2, $rows[$shopA->id]['product_count']);
        $this->assertSame(1, $rows[$shopB->id]['product_count']);
        $this->assertSame(2, $rows[$shopA->id]['user_count']); // owner + the staff member just created
    }

    public function test_admin_page_renders_with_shops_and_totals(): void
    {
        $this->createShopWithOwner();

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/shops/usage');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('shops')->has('totals.total_mb'));
    }

    public function test_a_shop_owner_cannot_reach_the_admin_only_page(): void
    {
        $response = $this->get('/admin/shops/usage');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_system_health_page_reports_this_apps_own_usage_not_just_shared_host_disk(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/system-health');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('appUsage.total_mb')->has('disk.total_gb'));
    }
}
