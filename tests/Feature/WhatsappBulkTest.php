<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\WhatsappBulkLog;
use App\Models\WhatsappCredential;
use App\Models\WhatsappMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Covers both what already existed (send(), gated behind a connected
 * credential + the whatsapp_bulk feature) and what this pass added:
 * saved reusable message templates and bulk contact import (paste/type
 * text and/or CSV), both landing as real Customer records so an imported
 * contact is immediately selectable and reusable everywhere else too.
 */
class WhatsappBulkTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithWhatsapp(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'whatsapp_bulk');
        WhatsappCredential::create([
            'shop_id' => $shop->id,
            'credentials' => ['phone_number_id' => '123456', 'access_token' => 'test-token'],
            'is_active' => true,
        ]);

        return [$shop, $owner];
    }

    // ---------------- index() ----------------

    public function test_index_lists_customers_recent_logs_and_templates(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001', 'due' => 0]);
        WhatsappMessageTemplate::create(['shop_id' => $shop->id, 'label' => 'অফার', 'send_type' => 'text', 'message' => 'ছাড় চলছে!']);

        $this->actingAs($owner, 'web')->get('/app/whatsapp-bulk')->assertInertia(fn ($page) => $page
            ->where('connected', true)
            ->has('customers', 1)
            ->has('templates', 1)
        );
    }

    public function test_not_connected_shows_up_as_such(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'whatsapp_bulk');

        $this->actingAs($owner, 'web')->get('/app/whatsapp-bulk')->assertInertia(fn ($page) => $page
            ->where('connected', false)
        );
    }

    // ---------------- send() — existing behavior, now regression-locked ----------------

    public function test_send_requires_a_connected_credential(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'whatsapp_bulk');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001']);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/send', [
            'send_type' => 'text', 'message' => 'hi', 'customer_ids' => [$customer->id],
        ])->assertStatus(422);
    }

    public function test_send_logs_the_attempt_and_reports_sent_vs_failed(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/send', [
            'send_type' => 'text', 'message' => 'hi', 'customer_ids' => [$customer->id],
        ])->assertRedirect();

        $log = WhatsappBulkLog::first();
        $this->assertSame(1, $log->recipients_count);
        $this->assertSame(1, $log->sent_count);
        $this->assertSame(0, $log->failed_count);
    }

    public function test_send_skips_customers_without_a_phone(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $noPhone = Customer::create(['shop_id' => $shop->id, 'name' => 'No Phone', 'phone' => null]);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/send', [
            'send_type' => 'text', 'message' => 'hi', 'customer_ids' => [$noPhone->id],
        ])->assertStatus(422);
    }

    public function test_send_route_requires_the_feature_grant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature NOT granted
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001']);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/send', [
            'send_type' => 'text', 'message' => 'hi', 'customer_ids' => [$customer->id],
        ])->assertStatus(403);
    }

    // ---------------- importContacts() — pasted text ----------------

    public function test_import_pasted_text_with_name_and_phone_pairs(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => "করিম মিয়া, 01712345678\nসালমা, 01812345678",
        ])->assertRedirect();

        $this->assertSame(2, Customer::count());
        $this->assertSame('করিম মিয়া', Customer::where('phone', '01712345678')->first()->name);
        $this->assertSame('সালমা', Customer::where('phone', '01812345678')->first()->name);
    }

    public function test_import_bare_phone_numbers_one_per_line(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => "01712345678\n01812345678\n01912345678",
        ])->assertRedirect();

        $this->assertSame(3, Customer::count());
        // no name given — falls back to the phone number itself, never blank
        $this->assertSame('01712345678', Customer::where('phone', '01712345678')->first()->name);
    }

    public function test_import_multiple_bare_numbers_crammed_onto_one_line(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => '01712345678, 01812345678, 01912345678',
        ])->assertRedirect();

        $this->assertSame(3, Customer::count());
    }

    public function test_import_matches_an_existing_customer_by_phone_instead_of_duplicating(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $existing = Customer::create(['shop_id' => $shop->id, 'name' => 'আগের করিম', 'phone' => '01712345678', 'due' => 500]);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => 'নতুন নাম, 01712345678',
        ])->assertRedirect();

        $this->assertSame(1, Customer::count());
        $this->assertSame('আগের করিম', $existing->fresh()->name, 'must not overwrite an existing customer\'s name/due');
        $this->assertEquals(500, $existing->fresh()->due);
    }

    public function test_import_flashes_the_resulting_customer_ids_for_auto_selection(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $response = $this->actingAs($owner, 'web')->from('/app/whatsapp-bulk')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => '01712345678',
        ]);

        $response->assertSessionHas('importedCustomerIds');
        $ids = session('importedCustomerIds');
        $this->assertSame([Customer::first()->id], $ids);
    }

    public function test_import_rejects_when_nothing_parseable_was_given(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => 'not a phone number at all',
        ])->assertSessionHasErrors('text');
        $this->assertSame(0, Customer::count());
    }

    // ---------------- importContacts() — CSV ----------------

    public function test_import_csv_with_name_and_phone_columns(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $csv = "name,phone\nকরিম মিয়া,01712345678\nসালমা,01812345678\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'file' => $file,
        ])->assertRedirect();

        $this->assertSame(2, Customer::count());
        $this->assertSame('করিম মিয়া', Customer::where('phone', '01712345678')->first()->name);
    }

    public function test_import_headerless_csv_treats_every_line_as_a_bare_phone_number(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $csv = "01712345678\n01812345678\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'file' => $file,
        ])->assertRedirect();

        $this->assertSame(2, Customer::count());
    }

    public function test_import_combines_pasted_text_and_csv_in_one_request(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $csv = "name,phone\nক,01712345678\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->actingAs($owner, 'web')->post('/app/whatsapp-bulk/import-contacts', [
            'text' => '01812345678',
            'file' => $file,
        ])->assertRedirect();

        $this->assertSame(2, Customer::count());
    }

    // ---------------- saved message templates (WhatsappTemplateController) ----------------

    public function test_owner_can_save_a_text_template(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-templates', [
            'label' => 'বাকি রিমাইন্ডার', 'send_type' => 'text', 'message' => 'আপনার বাকি আছে, দয়া করে পরিশোধ করুন।',
        ])->assertRedirect();

        $tpl = WhatsappMessageTemplate::first();
        $this->assertSame('বাকি রিমাইন্ডার', $tpl->label);
        $this->assertSame('text', $tpl->send_type);
    }

    public function test_owner_can_save_a_template_type_shortcut(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-templates', [
            'label' => 'অর্ডার আপডেট', 'send_type' => 'template', 'template_name' => 'order_update', 'language_code' => 'bn',
        ])->assertRedirect();

        $this->assertSame('order_update', WhatsappMessageTemplate::first()->template_name);
    }

    public function test_saving_a_template_requires_the_matching_field_for_its_send_type(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();

        $this->actingAs($owner, 'web')->post('/app/whatsapp-templates', [
            'label' => 'X', 'send_type' => 'text', // message omitted
        ])->assertSessionHasErrors('message');

        $this->actingAs($owner, 'web')->post('/app/whatsapp-templates', [
            'label' => 'Y', 'send_type' => 'template', // template_name omitted
        ])->assertSessionHasErrors('template_name');
    }

    public function test_owner_can_update_and_delete_a_template(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $tpl = WhatsappMessageTemplate::create(['shop_id' => $shop->id, 'label' => 'পুরনো', 'send_type' => 'text', 'message' => 'msg']);

        $this->actingAs($owner, 'web')->put("/app/whatsapp-templates/{$tpl->id}", [
            'label' => 'নতুন', 'send_type' => 'text', 'message' => 'updated',
        ])->assertRedirect();
        $this->assertSame('নতুন', $tpl->fresh()->label);

        $this->actingAs($owner, 'web')->delete("/app/whatsapp-templates/{$tpl->id}")->assertRedirect();
        $this->assertSame(0, WhatsappMessageTemplate::count());
    }

    public function test_a_shop_cannot_touch_another_shops_template(): void
    {
        [$shopA, $ownerA] = $this->shopWithWhatsapp();
        [$shopB] = $this->shopWithWhatsapp();
        $tplB = WhatsappMessageTemplate::create(['shop_id' => $shopB->id, 'label' => 'B-এর টেমপ্লেট', 'send_type' => 'text', 'message' => 'msg']);

        $this->actingAs($ownerA, 'web')->put("/app/whatsapp-templates/{$tplB->id}", [
            'label' => 'hacked', 'send_type' => 'text', 'message' => 'x',
        ])->assertStatus(404);

        $this->assertSame('B-এর টেমপ্লেট', $tplB->fresh()->label);
    }

    public function test_staff_cannot_reach_whatsapp_bulk_routes(): void
    {
        [$shop, $owner] = $this->shopWithWhatsapp();
        $staff = \App\Models\User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->get('/app/whatsapp-bulk')->assertStatus(403);
        $this->actingAs($staff, 'web')->post('/app/whatsapp-bulk/import-contacts', ['text' => '01712345678'])->assertStatus(403);
    }
}
