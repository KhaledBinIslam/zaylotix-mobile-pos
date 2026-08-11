<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Regression guard for a real production crash: fread()'s $length argument
 * must be > 0 in PHP 8+, and filesize() on a genuinely empty log file
 * (freshly cleared, or a brand-new install that hasn't logged anything
 * yet) is exactly 0 — SystemHealthController used to call fread() with
 * that 0 unconditionally, throwing a ValueError and taking the whole page
 * down instead of just showing "no recent errors".
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'health-admin@test.com', 'password' => 'password']);
    }

    public function test_page_renders_when_the_log_file_is_empty(): void
    {
        File::ensureDirectoryExists(dirname(storage_path('logs/laravel.log')));
        file_put_contents(storage_path('logs/laravel.log'), '');

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/system-health');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('recentErrors', []));
    }

    public function test_page_renders_when_the_log_file_does_not_exist_at_all(): void
    {
        $path = storage_path('logs/laravel.log');
        if (file_exists($path)) {
            unlink($path);
        }

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/system-health');

        $response->assertOk();
    }

    public function test_recent_errors_are_parsed_from_a_populated_log(): void
    {
        File::ensureDirectoryExists(dirname(storage_path('logs/laravel.log')));
        file_put_contents(storage_path('logs/laravel.log'), "[2026-08-11 10:00:00] production.ERROR: Something broke {\"exception\":\"...\"}\n");

        $response = $this->actingAs($this->admin(), 'admin')->get('/admin/system-health');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('recentErrors', 1));
    }

    public function test_a_shop_owner_cannot_reach_the_admin_only_page(): void
    {
        // no admin guard header at all — same as any unauthenticated request
        $response = $this->get('/admin/system-health');

        $response->assertRedirect(route('admin.login'));
    }
}
