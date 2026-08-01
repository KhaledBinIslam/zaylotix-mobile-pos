<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Regression test: the mobile/PWA API (routes/api.php) used to apply no
 * `perm:` gate at all on POS checkout or customer routes, even though the
 * equivalent web routes (routes/web.php) enforce `perm:pos` / `perm:customers`.
 * A cashier the owner deliberately withheld POS access from was blocked in
 * the browser (hidden button + server-side 403) but could still hit the API
 * directly and check out sales / read-or-create customers freely, since the
 * UI hiding the button was the only thing standing in the way.
 *
 * This also exercises the fix to EnsureUserPermission, which used to
 * hardcode Auth::guard('web') — meaning even adding the perm: middleware
 * naively would have 403'd every legitimate API call too, since no 'web'
 * session guard exists on a Sanctum-token request.
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function cashier(array $permissions): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900009999',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => $permissions,
        ]);

        return [$shop, $cashier];
    }

    public function test_cashier_without_pos_permission_is_blocked_from_api_checkout(): void
    {
        [, $cashier] = $this->cashier(['customers']);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/pos/checkout', [
            'items' => [],
            'payments' => [],
        ])->assertStatus(403);
    }

    public function test_cashier_without_customers_permission_is_blocked_from_api_customers(): void
    {
        [, $cashier] = $this->cashier(['pos']);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/customers')->assertStatus(403);
    }

    public function test_cashier_with_pos_permission_can_reach_api_checkout_gate(): void
    {
        [, $cashier] = $this->cashier(['pos']);
        Sanctum::actingAs($cashier);

        // Empty cart fails checkout's own validation (422), not the
        // permission gate (403) — proving the perm:pos middleware itself
        // let a correctly-permissioned cashier through.
        $this->postJson('/api/pos/checkout', [
            'items' => [],
            'payments' => [],
        ])->assertStatus(422);
    }
}
