<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Root-cause regression guard for a live bug: an app/admin page response
 * with no Cache-Control at all left the browser's own native HTTP cache
 * (a separate layer entirely from sw.js's own deliberate caching) free to
 * silently reuse an earlier response for the same URL. Concretely: a
 * restaurant order's "add item" POST, then an immediate Inertia partial
 * reload (GET, same URL) to refresh the cart, could come back with the
 * pre-add state — the item flashed onto the cart and vanished a moment
 * later. See SecurityHeaders.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_shop_app_pages_are_never_cached_by_the_browser(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        // Symfony's ResponseHeaderBag manages Cache-Control specially — it
        // parses/normalizes directives (and adds its own, e.g. `private`
        // once a session cookie is involved) rather than keeping the exact
        // literal string passed to headers->set(), so assert on the
        // directive being present rather than an exact string match.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_admin_pages_are_never_cached_by_the_browser(): void
    {
        $admin = \App\Models\Admin::create(['name' => 'Admin', 'email' => 'sechead-'.uniqid().'@test.com', 'password' => 'password']);

        $response = $this->actingAs($admin, 'admin')->get('/admin/dashboard');

        // Symfony's ResponseHeaderBag manages Cache-Control specially — it
        // parses/normalizes directives (and adds its own, e.g. `private`
        // once a session cookie is involved) rather than keeping the exact
        // literal string passed to headers->set(), so assert on the
        // directive being present rather than an exact string match.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** Every response still gets the baseline hardening headers, regardless of path. */
    public function test_every_response_gets_the_baseline_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
