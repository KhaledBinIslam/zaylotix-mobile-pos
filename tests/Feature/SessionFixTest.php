<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * See docs/SESSION_FIX.md. A genuine TokenMismatchException (419) can't
 * be reproduced in a feature test — Laravel's own CSRF middleware skips
 * verification entirely while $app->runningUnitTests() is true, by
 * design, for every test in this suite — so that part of the fix
 * (fetchWithSessionRetry.js's 419 branch) is covered at the JS unit-test
 * level instead (fetchWithSessionRetry.test.js). This file covers what
 * IS reachable over real HTTP: the new token-refresh endpoint itself, and
 * the "return to where you actually were" redirect fix.
 */
class SessionFixTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_csrf_token_endpoint_returns_the_current_sessions_token(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->getJson('/app/csrf-token');

        $response->assertOk();
        $this->assertSame(session()->token(), $response->json('token'));
    }

    public function test_csrf_token_endpoint_requires_login(): void
    {
        $this->getJson('/app/csrf-token')->assertStatus(401);
    }

    /**
     * Real gap this closes: before, a genuinely-logged-out visit to any
     * protected page redirected straight to the generic login screen, with
     * no memory of where the person actually was — after logging back in
     * they'd land on their default home page instead of back on, say, the
     * Stock page they were trying to reach. redirect()->guest() (not a
     * plain redirect()->route()) stashes that as the session's "intended"
     * URL, which LoginController::store()'s existing redirect()->intended()
     * already knows how to read back.
     */
    public function test_an_unauthenticated_visit_remembers_where_to_return_after_login(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $owner->update(['password' => bcrypt('secret123')]);

        $response = $this->get('/app/stock');
        $response->assertRedirect(route('login'));
        $this->assertSame(url('/app/stock'), session('url.intended'));

        $login = $this->post('/login', ['login' => $owner->phone, 'password' => 'secret123']);
        $login->assertRedirect(url('/app/stock'));
    }

    /** A background poll must still get a plain, catchable 401 — never the guest-redirect path, which it would otherwise silently follow straight off the current page (the original bug this distinction exists for, see EnsureShopUser's own docblock). */
    public function test_a_poll_request_while_logged_out_still_gets_a_plain_401_not_a_redirect(): void
    {
        $response = $this->get('/app/stock', ['X-Inertia-Poll' => 'true']);

        $response->assertStatus(401);
    }
}
