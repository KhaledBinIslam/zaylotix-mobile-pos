<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Root-cause regression guard for a live bug hit twice: first on /app/*
 * (a restaurant order's add-item POST + reload could come back with the
 * pre-add state — item flashed onto the cart and vanished), then again on
 * the PUBLIC /login page reached directly, which rendered as raw JSON
 * instead of the actual page (a Link-click's Inertia XHR JSON response for
 * that URL got silently replayed back for a later real navigation). Every
 * response now gets Cache-Control: no-store unconditionally — see
 * SecurityHeaders.
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

    /**
     * The actual bug: /login (and /, /signup — any public guest page) must
     * never be cached by the browser either, not just /app/* and /admin/*.
     * A guest reaching /login by typing the URL/opening a fresh tab, after
     * an earlier Inertia Link click had fetched it as raw XHR JSON, was
     * getting that stale JSON response back instead of the real page.
     */
    public function test_public_guest_pages_are_never_cached_by_the_browser_either(): void
    {
        foreach (['/', '/login', '/signup'] as $path) {
            $response = $this->get($path);

            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), "expected no-store on {$path}");
        }
    }
}
