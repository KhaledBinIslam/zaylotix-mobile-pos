<?php

namespace Tests\Feature;

use App\Jobs\GenerateShopBackupJob;
use App\Models\Product;
use App\Models\ShopBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopBackupTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_request_a_backup_and_it_gets_queued(): void
    {
        Bus::fake();
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->post('/app/shop-backups', ['format' => 'sql']);

        $response->assertRedirect();
        $backup = ShopBackup::first();
        $this->assertSame($shop->id, $backup->shop_id);
        $this->assertSame('sql', $backup->format);
        $this->assertSame('pending', $backup->status);
        Bus::assertDispatched(GenerateShopBackupJob::class, fn ($job) => $job->shopBackup->is($backup));
    }

    public function test_staff_cannot_reach_shop_backup_routes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $staff = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01799999999',
            'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);

        $this->actingAs($staff, 'web')->get('/app/shop-backups')->assertForbidden();
        $this->actingAs($staff, 'web')->post('/app/shop-backups', ['format' => 'sql'])->assertForbidden();
    }

    public function test_the_generated_sql_backup_only_contains_that_shops_data(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        Product::create(['shop_id' => $shopA->id, 'name' => 'A-Only-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'B-Only-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $backup = ShopBackup::create(['shop_id' => $shopA->id, 'requested_by' => $ownerA->id, 'format' => 'sql', 'status' => 'pending']);
        (new GenerateShopBackupJob($backup))->handle();

        $backup->refresh();
        $this->assertSame('done', $backup->status);
        $this->assertNotNull($backup->file_path);

        $sql = Storage::disk('local')->get($backup->file_path);
        $this->assertStringContainsString('A-Only-Product', $sql);
        $this->assertStringNotContainsString('B-Only-Product', $sql);
    }

    public function test_a_shop_cannot_download_or_delete_another_shops_backup(): void
    {
        Storage::fake('local');
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        $foreignBackup = ShopBackup::create([
            'shop_id' => $shopB->id, 'format' => 'sql', 'status' => 'done', 'file_path' => 'shop-backups/x/fake.sql',
        ]);
        Storage::disk('local')->put('shop-backups/x/fake.sql', '-- B only');

        $this->actingAs($ownerA, 'web')->get("/app/shop-backups/{$foreignBackup->id}/download")->assertStatus(404);
        $this->actingAs($ownerA, 'web')->delete("/app/shop-backups/{$foreignBackup->id}")->assertStatus(404);
        $this->assertTrue(Storage::disk('local')->exists('shop-backups/x/fake.sql'));
    }
}
