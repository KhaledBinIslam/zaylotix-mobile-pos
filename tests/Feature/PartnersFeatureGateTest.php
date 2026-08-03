<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * `accounts/partners` used to only sit behind `feature:accounts` (the parent
 * route group) — a shop with 'accounts' granted but 'partners' specifically
 * NOT granted (the admin can untick features individually, they aren't
 * forced to move as a pair even though every business-type default grants
 * them together) could still reach the partners endpoints directly, even
 * though the UI correctly hides the link for exactly that shop. This locks
 * in the fix: the route itself now requires 'partners', not just 'accounts'.
 */
class PartnersFeatureGateTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_partners_route_is_blocked_when_only_accounts_feature_is_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');

        $response = $this->actingAs($owner, 'web')->get('/app/accounts/partners');

        $response->assertStatus(403);
    }

    public function test_partners_route_works_once_partners_feature_is_also_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');

        $response = $this->actingAs($owner, 'web')->get('/app/accounts/partners');

        $response->assertOk();
    }
}
