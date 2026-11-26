<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * QA finding, 2026-11-26: logging in issues a new CSRF token, but the page is not reloaded, so the <meta> tag
 * kept the old one and the first fetch() after login got a 419 (the POS then reloaded itself and popped the cart
 * sheet open). Every Inertia response now carries the session's current token for the client to copy into the tag.
 */
class CsrfTokenRefreshAfterLoginTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function inertiaGet(string $url, array $extra = [])
    {
        $version = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

        return $this->get($url, array_merge(['X-Inertia' => 'true', 'X-Inertia-Version' => $version], $extra));
    }

    public function test_an_inertia_response_carries_the_sessions_current_token(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->inertiaGet('/app/pos');

        $this->assertSame(session()->token(), $response->json('props.csrf_token'));
        $this->assertNotEmpty($response->json('props.csrf_token'));
    }

    public function test_after_login_the_page_gets_the_new_token_not_the_one_from_the_login_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'memo_print');

        $this->get('/login')->assertOk();
        $loginPageToken = session()->token();

        $this->post('/login', ['login' => $owner->phone, 'password' => 'password'])->assertRedirect();
        $afterLogin = $this->inertiaGet('/app/pos');

        $this->assertNotSame($loginPageToken, session()->token(), 'login really does issue a new token');
        $this->assertSame(session()->token(), $afterLogin->json('props.csrf_token'), 'and the page is told about it');
    }

    public function test_the_client_copies_the_token_into_the_meta_tag_on_every_navigation(): void
    {
        $js = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("router.on('navigate'", $js);
        $this->assertStringContainsString('csrf_token', $js);
        $this->assertStringContainsString('meta[name="csrf-token"]', $js);
    }
}
