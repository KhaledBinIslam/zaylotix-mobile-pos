<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Admin-only whole-database backup/restore — deliberately never reachable
 * by a shop owner (see BackupController's class docblock for why: the dump
 * covers every tenant at once). Restore itself is mysql-only; these tests
 * run on the sqlite test DB, so restore() is only exercised up to its
 * connection-type guard, never an actual destructive exec.
 */
class AdminBackupTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'backup-admin@test.com', 'password' => 'password']);
    }

    public function test_admin_can_list_backup_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/zaylotix-pos-2026-01-01_000000.sqlite', 'fake dump content');

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/backups');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('files', 1)
            ->where('files.0.name', 'zaylotix-pos-2026-01-01_000000.sqlite')
        );
    }

    public function test_admin_can_trigger_a_backup_now(): void
    {
        // the test suite's sqlite connection is always ':memory:' (see
        // phpunit.xml) — BackupDatabase's own sqlite branch deliberately
        // has nothing to copy in that case, so this exercises the route
        // wiring (auth, redirect, error surfaced to the admin) rather than
        // a real file landing on disk, which only a file-backed sqlite or
        // mysql connection could actually produce
        Storage::fake('local');

        $response = $this->actingAs($this->admin(), 'admin')->post('/admin/backups');

        $response->assertRedirect();
        $response->assertSessionHasErrors('backup');
    }

    public function test_admin_can_download_a_backup_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/test-backup.sql', 'dump content here');

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/backups/test-backup.sql/download');

        $response->assertOk();
    }

    public function test_download_rejects_path_traversal(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret-outside.txt', 'should never be reachable');

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/backups/..%2Fsecret-outside.txt/download');

        $response->assertStatus(404);
    }

    public function test_download_rejects_a_non_backup_extension(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/not-a-backup.txt', 'nope');

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/backups/not-a-backup.txt/download');

        $response->assertStatus(422);
    }

    public function test_admin_can_delete_a_backup_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/old-backup.sql', 'old dump');

        $this->actingAs($this->admin(), 'admin')->delete('/admin/backups/old-backup.sql')->assertRedirect();

        Storage::disk('local')->assertMissing('backups/old-backup.sql');
    }

    public function test_restore_requires_the_literal_confirmation_phrase(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/test-backup.sql', 'dump content');

        $response = $this->actingAs($this->admin(), 'admin')->post('/admin/backups/test-backup.sql/restore', [
            'confirm' => 'yes please',
        ]);

        $response->assertSessionHasErrors('confirm');
    }

    public function test_restore_is_rejected_outright_on_a_non_mysql_connection(): void
    {
        // the test suite runs on sqlite — restore must refuse before
        // attempting anything destructive, rather than trying to run a
        // mysql-specific restore command against the wrong database
        Storage::fake('local');
        Storage::disk('local')->put('backups/test-backup.sql', 'dump content');

        $response = $this->actingAs($this->admin(), 'admin')->post('/admin/backups/test-backup.sql/restore', [
            'confirm' => 'RESTORE',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_shop_owner_can_never_reach_the_backup_routes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/admin/backups')->assertRedirect();
    }
}
