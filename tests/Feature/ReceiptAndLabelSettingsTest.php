<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Covers the new customizable-memo/customizable-barcode-label feature: a
 * shop with no saved memo_settings/label_settings at all must render
 * exactly like it always did (Shop::MEMO_SETTINGS_DEFAULTS/
 * LABEL_SETTINGS_DEFAULTS mirror the pre-existing hardcoded behavior), and
 * every new toggle an owner never touched stays off.
 */
class ReceiptAndLabelSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_shop_with_no_saved_settings_resolves_to_the_pre_existing_defaults(): void
    {
        [$shop] = $this->createShopWithOwner();

        $memo = $shop->memoSettings();
        $label = $shop->labelSettings();

        $this->assertTrue($memo['show_bin']); // BIN already showed automatically once set -- must stay on
        $this->assertFalse($memo['show_email']); // brand new field -- must default off
        $this->assertFalse($memo['show_cashier']);
        $this->assertFalse($memo['show_shop_qr']); // brand new field -- must default off
        $this->assertSame('58mm', $memo['paper_width']); // the pre-existing fixed size

        $this->assertSame('38x25', $label['size']); // the pre-existing fixed size
        $this->assertSame(2, $label['per_row']); // the pre-existing 2-per-row grid
        $this->assertSame('both', $label['price_mode']); // the pre-existing default
        $this->assertTrue($label['show_barcode_number']);
    }

    public function test_the_shared_shop_prop_carries_the_resolved_not_raw_settings(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $resolved = $shop->toArrayForUser($owner);

        $this->assertIsArray($resolved['memo_settings']);
        $this->assertArrayHasKey('show_email', $resolved['memo_settings']);
        $this->assertIsArray($resolved['label_settings']);
        $this->assertArrayHasKey('size', $resolved['label_settings']);
    }

    public function test_owner_can_save_business_info_fields(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/business-info', [
            'email' => 'shop@example.com', 'website' => 'facebook.com/shop',
            'trade_license_no' => 'TL-123', 'tin_no' => 'TIN-456',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertSame('shop@example.com', $shop->email);
        $this->assertSame('facebook.com/shop', $shop->website);
        $this->assertSame('TL-123', $shop->trade_license_no);
        $this->assertSame('TIN-456', $shop->tin_no);
    }

    public function test_owner_can_save_memo_settings_and_they_stick(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $payload = array_merge(Shop::MEMO_SETTINGS_DEFAULTS, [
            'show_cashier' => true, 'show_customer_phone' => true, 'paper_width' => '80mm',
        ]);

        $this->actingAs($owner, 'web')->patch('/app/settings/memo', $payload)->assertRedirect();

        $shop->refresh();
        $this->assertTrue($shop->memoSettings()['show_cashier']);
        $this->assertTrue($shop->memoSettings()['show_customer_phone']);
        $this->assertSame('80mm', $shop->memoSettings()['paper_width']);
        $this->assertTrue($shop->memoSettings()['show_bin']); // untouched defaults still present
    }

    public function test_owner_can_enable_the_shop_qr_setting(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $payload = array_merge(Shop::MEMO_SETTINGS_DEFAULTS, ['show_shop_qr' => true]);

        $this->actingAs($owner, 'web')->patch('/app/settings/memo', $payload)->assertRedirect();

        $shop->refresh();
        $this->assertTrue($shop->memoSettings()['show_shop_qr']);
    }

    public function test_owner_can_save_label_settings(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $payload = array_merge(Shop::LABEL_SETTINGS_DEFAULTS, [
            'size' => '50x25', 'per_row' => 1, 'price_mode' => 'none',
        ]);

        $this->actingAs($owner, 'web')->patch('/app/settings/label', $payload)->assertRedirect();

        $shop->refresh();
        $this->assertSame('50x25', $shop->labelSettings()['size']);
        $this->assertSame(1, $shop->labelSettings()['per_row']);
        $this->assertSame('none', $shop->labelSettings()['price_mode']);
    }

    public function test_custom_label_size_requires_both_dimensions(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $payload = array_merge(Shop::LABEL_SETTINGS_DEFAULTS, ['size' => 'custom', 'custom_width_mm' => null, 'custom_height_mm' => null]);

        $this->actingAs($owner, 'web')->patch('/app/settings/label', $payload)->assertSessionHasErrors('custom_width_mm');

        $this->assertSame('38x25', $shop->fresh()->labelSettings()['size']); // unchanged
    }

    public function test_a_cashier_without_the_settings_permission_cannot_change_memo_settings(): void
    {
        [$shop] = $this->createShopWithOwner();
        $cashier = \App\Models\User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01700000111', 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos']]);

        $this->actingAs($cashier, 'web')->patch('/app/settings/memo', Shop::MEMO_SETTINGS_DEFAULTS)->assertForbidden();
    }

    public function test_creating_a_branch_copies_the_main_shops_memo_and_label_settings(): void
    {
        [$main, ] = $this->createShopWithOwner(['business_type_id' => \App\Models\BusinessType::create(['slug' => 'g2', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true])->id]);
        $admin = \App\Models\Admin::create(['name' => 'Super', 'email' => 'branchsettings@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $main->update([
            'email' => 'main@shop.com', 'bin_no' => 'BIN-999',
            'memo_settings' => array_merge(Shop::MEMO_SETTINGS_DEFAULTS, ['show_cashier' => true]),
            'label_settings' => array_merge(Shop::LABEL_SETTINGS_DEFAULTS, ['size' => '50x25']),
        ]);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$main->id}/branches", [
            'name' => 'Branch A', 'phone' => '01799999999', 'plan' => 'monthly',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $branch = Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->first();
        $this->assertSame('main@shop.com', $branch->email);
        $this->assertSame('BIN-999', $branch->bin_no);
        $this->assertTrue($branch->memoSettings()['show_cashier']);
        $this->assertSame('50x25', $branch->labelSettings()['size']);
    }

    public function test_checkout_response_includes_the_cashier_for_the_just_billed_receipt(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = \App\Models\Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertOk();
        $this->assertSame($owner->id, $response->json('sale.user.id'));
    }
}
