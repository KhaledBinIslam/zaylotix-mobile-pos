<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Every forced logout leaves a trace. Baby Park, 2026-11-24: staff were
 * "logged out again and again" and laravel.log had nothing — both places
 * that destroy or refuse a session did so silently. Behaviour is unchanged;
 * only the warning is new.
 */
class ForcedLogoutLoggingTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_an_inactive_shop_logout_is_logged_with_the_resolved_shop_and_billing_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'inactive']);
        Log::spy();

        $this->actingAs($owner, 'web')->get('/app/pos')->assertRedirect(route('login'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx) => $msg === '[forced-logout] subscription check failed'
            && $ctx['user_id'] === $owner->id
            && $ctx['resolved_shop_id'] === $shop->id
            && $ctx['resolved_status'] === 'inactive'
            && $ctx['billing_shop_id'] === $shop->id
            && $ctx['url'] === 'app/pos'
            && $ctx['poll'] === false);
    }

    public function test_a_request_carrying_a_session_cookie_but_no_user_is_logged(): void
    {
        Log::spy();
        $request = Request::create('/app/pos', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->cookies->set(config('session.cookie'), 'stale-cookie-value');

        try {
            (new EnsureShopUser)->handle($request, fn () => response('next'));
            $this->fail('expected the 401 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx) => $msg === '[forced-logout] session cookie present but no user'
            && $ctx['url'] === 'app/pos' && $ctx['json'] === true);
    }

    public function test_a_plain_first_visit_without_a_cookie_is_not_logged(): void
    {
        Log::spy();

        $this->get('/app/pos')->assertRedirect(route('login'));

        Log::shouldNotHaveReceived('warning');
    }
}
