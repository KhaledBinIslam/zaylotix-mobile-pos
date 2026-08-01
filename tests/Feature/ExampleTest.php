<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** The app has no public landing page — root always sends guests to login. */
    public function test_the_root_url_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    /**
     * Regression test for ERR_TOO_MANY_REDIRECTS: an authenticated shop user
     * hitting `/` (or `/login` directly, e.g. an old bookmark/tab) must land
     * on their own dashboard, not bounce back to /login — this app has no
     * route literally named `home`/`dashboard`, so Laravel's `guest`
     * middleware's default fallback used to send them straight back to `/`,
     * which redirected to `/login`, which redirected to `/`... forever.
     */
    public function test_an_already_logged_in_shop_user_visiting_root_goes_to_their_home_not_back_to_login(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/');

        $response->assertRedirect(route('app.home'));
    }

    public function test_an_already_logged_in_shop_user_visiting_login_does_not_loop(): void
    {
        [, $owner] = $this->createShopWithOwner();

        // guest middleware bounces them off /login back to `/`, which must
        // resolve immediately to app.home rather than back to /login —
        // following the full chain must land on a real 200 page, not loop
        $response = $this->actingAs($owner, 'web')->get('/login');

        $response->assertRedirect('/');
        $this->followingRedirects()->actingAs($owner, 'web')->get('/login')->assertOk();
    }
}
