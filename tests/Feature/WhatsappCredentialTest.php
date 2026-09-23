<?php

namespace Tests\Feature;

use App\Models\WhatsappCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Same guard as PaymentGatewayCredentialTest — a row saved under an
 * APP_KEY that's since rotated throws DecryptException on every read of
 * ->credentials, which used to take down the whole settings sheet
 * (WhatsappCredentialController::index() had no try/catch of its own)
 * instead of just degrading that one row's summary.
 */
class WhatsappCredentialTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_an_undecryptable_credential_row_degrades_to_generic_instead_of_crashing(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $credential = WhatsappCredential::create([
            'shop_id' => $shop->id,
            'credentials' => ['phone_number_id' => 'placeholder', 'access_token' => 'placeholder'],
            'is_active' => true,
        ]);
        DB::table('whatsapp_credentials')->where('id', $credential->id)->update(['credentials' => 'not-actually-encrypted-garbage']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/whatsapp-business');

        $response->assertOk();
        $this->assertSame('configured', $response->json('configured.masked_summary'));
    }

    public function test_masked_summary_never_exposes_the_access_token(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        WhatsappCredential::create([
            'shop_id' => $shop->id,
            'credentials' => ['phone_number_id' => '9998887776', 'access_token' => 'super-secret-token'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/whatsapp-business');

        $response->assertOk();
        $this->assertStringNotContainsString('super-secret-token', json_encode($response->json()));
        $this->assertStringStartsWith('9998', $response->json('configured.masked_summary'));
    }
}
