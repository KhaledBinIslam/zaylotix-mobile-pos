<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A server error must be traceable: the user sees a short reference on screen,
 * the same reference is in the log with who/where/why, and the admin health
 * page lists it. And the page sweep must really catch a broken page.
 */
class IncidentReferenceAndSweepTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_a_server_error_shows_a_reference_and_logs_it_with_context(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Route::middleware(['web', 'shop'])->get('/app/__boom', fn () => throw new \RuntimeException('database exploded'));
        Log::spy();

        $response = $this->actingAs($owner, 'web')->get('/app/__boom', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())]);

        $response->assertStatus(500);
        $props = $response->json('props');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $props['incident']);

        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, "[incident {$props['incident']}]")
            && str_contains($msg, 'database exploded')
            && ($ctx['user_id'] ?? null) === $owner->id
            && ($ctx['shop_id'] ?? null) === $shop->id
            && str_contains($ctx['url'] ?? '', '__boom'))->once();
    }

    public function test_a_json_caller_gets_the_reference_in_the_message_too(): void
    {
        [, $owner] = $this->createShopWithOwner();
        Route::middleware(['web', 'shop'])->get('/app/__boomjson', fn () => throw new \RuntimeException('x'));

        $r = $this->actingAs($owner, 'web')->getJson('/app/__boomjson');

        $r->assertStatus(500);
        $this->assertStringContainsString('রেফারেন্স:', $r->json('message'));
        $this->assertNotNull($r->json('incident'));
    }

    public function test_the_admin_health_page_lists_incidents_and_phone_errors_and_forced_logouts(): void
    {
        $log = storage_path('logs/laravel.log');
        @mkdir(dirname($log), 0777, true);
        $backup = is_file($log) ? file_get_contents($log) : null;
        file_put_contents($log, implode("\n", [
            '[2026-11-25 10:00:00] testing.ERROR: [incident ABC123] RuntimeException: boom {"incident":"ABC123"}',
            '[2026-11-25 10:01:00] testing.WARNING: [client-js-error] scanner failed {"url":"/app/returns"}',
            '[2026-11-25 10:02:00] testing.WARNING: [forced-logout] session cookie present but no user {"url":"app/pos"}',
        ])."\n");

        try {
            $admin = Admin::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super_admin']);
            $this->actingAs($admin, 'admin')->get('/admin/system-health')->assertOk()->assertInertia(fn ($page) => $page
                ->component('Admin/SystemHealth/Index')
                ->has('incidents', 3)
                ->where('incidents.2.ref', 'ABC123')
                ->where('incidents.2.kind', 'server')
                ->where('incidents.1.kind', 'phone')
                ->where('incidents.0.kind', 'logout'));
        } finally {
            $backup === null ? @unlink($log) : file_put_contents($log, $backup);
        }
    }

    public function test_the_page_sweep_passes_on_a_healthy_shop_and_fails_on_a_broken_page(): void
    {
        $this->createShopWithOwner(['account_type' => 'paid']);

        // sqlite (the test DB) has no MySQL DATE_FORMAT, so the company dashboard cannot run here — the sweep
        // reporting it is itself proof the sweep sees real 500s. Every other page must open cleanly.
        Artisan::call('zaylotix:self-check', ['--sweep' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('pages tried', $out);
        preg_match_all('/FAIL (\/app\/\S+) → 5\d\d/', $out, $m);
        $this->assertSame(['/app/company-dashboard'], $m[1], $out);

        Route::middleware(['web', 'shop'])->get('/app/zz-broken-page', fn () => throw new \RuntimeException('broken'));
        $this->artisan('zaylotix:self-check', ['--sweep' => true])
            ->expectsOutputToContain('FAIL /app/zz-broken-page')
            ->assertFailed();
    }
}
