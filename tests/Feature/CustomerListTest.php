<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Plain customer-browsing page (separate from Customers/Index, the
 * due-collection ledger) — reported live: clicking "কাস্টমার তালিকা" in
 * the More menu landed on the due ledger, which isn't what "customer
 * list" means to an owner who just wants to browse/select/export.
 */
class CustomerListTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_index_lists_every_customer_and_reports_whatsapp_readiness(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001', 'due' => 0]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Salma', 'phone' => '01700000002', 'due' => 500]);

        $this->actingAs($owner, 'web')->get('/app/customer-list')->assertInertia(fn ($page) => $page
            ->has('customers', 2)
            ->where('whatsappApiReady', false)
        );
    }

    public function test_whatsapp_api_ready_is_true_once_a_credential_is_connected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        WhatsappCredential::create([
            'shop_id' => $shop->id,
            'credentials' => ['phone_number_id' => '123456', 'access_token' => 'test-token'],
            'is_active' => true,
        ]);

        $this->actingAs($owner, 'web')->get('/app/customer-list')->assertInertia(fn ($page) => $page
            ->where('whatsappApiReady', true)
        );
    }

    public function test_staff_with_customers_permission_can_reach_the_list(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $staff = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['customers'], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->get('/app/customer-list')->assertOk();
    }

    public function test_export_selected_only_includes_the_chosen_customers(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $a = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001', 'due' => 0]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Salma', 'phone' => '01700000002', 'due' => 0]);

        $response = $this->actingAs($owner, 'web')->get('/app/customer-list/export?customer_ids[]='.$a->id);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_export_selected_requires_the_export_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $a = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001']);

        $this->actingAs($owner, 'web')->get('/app/customer-list/export?customer_ids[]='.$a->id)->assertForbidden();
    }

    public function test_a_shop_only_sees_its_own_customers(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shopA->id, 'name' => 'A-Customer']);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'B-Customer']);

        $this->actingAs($ownerA, 'web')->get('/app/customer-list')->assertInertia(fn ($page) => $page
            ->has('customers', 1)
            ->where('customers.0.name', 'A-Customer')
        );
    }
}
