<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Platform-wide branding — the logo an admin uploads here shows in the
 * admin panel and, as the "product owner" credit, on every shop's printed
 * memo/labels via HandleInertiaRequests' shared `platformLogoUrl` prop.
 */
class SiteSettingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_admin_can_upload_a_platform_logo(): void
    {
        Storage::fake('public');
        $admin = Admin::create(['name' => 'Admin', 'email' => 'site-admin@test.com', 'password' => 'password']);

        $response = $this->actingAs($admin, 'admin')->post('/admin/site-settings', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertRedirect();
        $setting = SiteSetting::current();
        $this->assertNotNull($setting->logo_path);
        Storage::disk('public')->assertExists($setting->logo_path);
    }

    public function test_uploading_a_new_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = Admin::create(['name' => 'Admin', 'email' => 'site-admin2@test.com', 'password' => 'password']);
        $this->actingAs($admin, 'admin')->post('/admin/site-settings', ['logo' => UploadedFile::fake()->image('first.png')]);
        $oldPath = SiteSetting::current()->logo_path;

        $this->actingAs($admin, 'admin')->post('/admin/site-settings', ['logo' => UploadedFile::fake()->image('second.png')]);

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNotEquals($oldPath, SiteSetting::current()->fresh()->logo_path);
    }

    public function test_admin_can_remove_the_platform_logo(): void
    {
        Storage::fake('public');
        $admin = Admin::create(['name' => 'Admin', 'email' => 'site-admin3@test.com', 'password' => 'password']);
        $this->actingAs($admin, 'admin')->post('/admin/site-settings', ['logo' => UploadedFile::fake()->image('logo.png')]);
        $path = SiteSetting::current()->logo_path;

        $this->actingAs($admin, 'admin')->delete('/admin/site-settings')->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull(SiteSetting::current()->fresh()->logo_path);
    }

    public function test_shop_user_cannot_access_site_settings(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/admin/site-settings')->assertRedirect(route('admin.login'));
    }

    public function test_shop_pages_share_the_platform_logo_url_once_uploaded(): void
    {
        Storage::fake('public');
        $admin = Admin::create(['name' => 'Admin', 'email' => 'site-admin4@test.com', 'password' => 'password']);
        $this->actingAs($admin, 'admin')->post('/admin/site-settings', ['logo' => UploadedFile::fake()->image('logo.png')]);

        [, $owner] = $this->createShopWithOwner();
        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('platformLogoUrl', fn ($url) => ! empty($url)));
    }
}
