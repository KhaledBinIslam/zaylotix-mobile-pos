<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Bring-your-own-key credential storage — the two things that matter most:
 * nothing is ever stored in plain text (the raw DB column must be
 * unreadable without APP_KEY), and nothing sensitive ever comes back out
 * through the API a cashier/browser could see (maskedSummary() only).
 */
class PaymentGatewayCredentialTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_credentials_are_encrypted_at_rest_in_the_raw_column(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/payment-gateways/sslcommerz', [
            'store_id' => 'test_store_123',
            'store_passwd' => 'super-secret-password',
            'sandbox' => true,
        ])->assertRedirect();

        $rawValue = DB::table('payment_gateway_credentials')->where('shop_id', $shop->id)->value('credentials');

        $this->assertStringNotContainsString('super-secret-password', $rawValue);
        $this->assertStringNotContainsString('test_store_123', $rawValue);

        // but the model itself, which is what the app's own code reads,
        // transparently decrypts it back to a normal array
        $credential = PaymentGatewayCredential::where('shop_id', $shop->id)->first();
        $this->assertSame('test_store_123', $credential->credentials['store_id']);
        $this->assertSame('super-secret-password', $credential->credentials['store_passwd']);
    }

    public function test_masked_summary_never_exposes_the_secret(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web')->post('/app/payment-gateways/sslcommerz', [
            'store_id' => 'test_store_123', 'store_passwd' => 'super-secret-password', 'sandbox' => true,
        ]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/payment-gateways');

        $response->assertOk();
        $body = $response->json();
        $this->assertStringNotContainsString('super-secret-password', json_encode($body));
        $this->assertStringStartsWith('tes', $body['configured']['sslcommerz']['masked_summary']);
    }

    public function test_a_cashier_cannot_configure_or_view_gateway_credentials(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900007777',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);

        $this->actingAs($cashier, 'web')->getJson('/app/payment-gateways')->assertForbidden();
        $this->actingAs($cashier, 'web')->post('/app/payment-gateways/sslcommerz', [
            'store_id' => 'x', 'store_passwd' => 'y', 'sandbox' => true,
        ])->assertForbidden();
    }

    public function test_owner_can_toggle_and_disconnect_a_gateway(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web')->post('/app/payment-gateways/sslcommerz', [
            'store_id' => 'x', 'store_passwd' => 'y', 'sandbox' => true,
        ]);

        $this->actingAs($owner, 'web')->patch('/app/payment-gateways/sslcommerz/toggle', ['is_active' => false])->assertRedirect();
        $this->assertFalse((bool) PaymentGatewayCredential::where('shop_id', $shop->id)->first()->is_active);

        $this->actingAs($owner, 'web')->delete('/app/payment-gateways/sslcommerz')->assertRedirect();
        $this->assertSame(0, PaymentGatewayCredential::where('shop_id', $shop->id)->count());
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/payment-gateways/fakepay', [
            'anything' => 'x',
        ])->assertStatus(404);
    }

    public function test_credentials_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->actingAs($ownerB, 'web')->post('/app/payment-gateways/sslcommerz', [
            'store_id' => 'shop_b_store', 'store_passwd' => 'shop_b_pass', 'sandbox' => true,
        ]);

        $response = $this->actingAs($ownerA, 'web')->getJson('/app/payment-gateways');

        $response->assertOk();
        $this->assertEmpty($response->json('configured'));
    }
}
