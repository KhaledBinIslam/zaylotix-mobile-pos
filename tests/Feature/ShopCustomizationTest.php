<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Shop;
use App\Support\ShopCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 40 (docs/FIX_PLAN.md, docs/CUSTOMIZATION_AUDIT.md) — the
 * shop-customization layer's guarantees: শপ > ব্যবসা > ডিফল্ট precedence,
 * no tenant leaks, impersonation sees exactly what the owner sees, a
 * never-customized shop resolves to byte-identical defaults (the snapshot
 * that proves existing shops' output can't change), and the admin
 * panel/command surfaces.
 */
class ShopCustomizationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main, array $attrs = []): Shop
    {
        return Shop::create(array_merge([
            'name' => 'Branch '.uniqid(),
            'phone' => '018'.random_int(10000000, 99999999),
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(),
            'subscription_expiry' => now()->addMonth()->toDateString(),
            'parent_shop_id' => $main->id,
            'onboarded_at' => now(),
        ], $attrs));
    }

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'cust-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    private function row(array $effective, string $key): array
    {
        foreach ($effective as $items) {
            foreach ($items as $row) {
                if ($row['key'] === $key) {
                    return $row;
                }
            }
        }
        $this->fail("customization row '{$key}' not found");
    }

    public function test_label_header_precedence_business_over_default(): void
    {
        [$main] = $this->createShopWithOwner(['name' => 'Baby Park']);
        $branch = $this->createBranch($main, ['name' => 'Liberty']);

        // no header anywhere -> each shop falls back to its own name (ডিফল্ট)
        $this->assertSame('Baby Park', $this->row(ShopCustomization::effective($main), 'label_header')['value']);
        $branchRow = $this->row(ShopCustomization::effective($branch), 'label_header');
        $this->assertSame('Liberty', $branchRow['value']);
        $this->assertSame(ShopCustomization::SOURCE_DEFAULT, $branchRow['source']);

        // set once on the main shop -> business-wide, both resolve to it
        $main->update(['label_header' => 'Baby Park & Liberty']);
        $branch->refresh()->unsetRelation('parentShop');

        $mainRow = $this->row(ShopCustomization::effective($main), 'label_header');
        $branchRow = $this->row(ShopCustomization::effective($branch), 'label_header');
        $this->assertSame('Baby Park & Liberty', $mainRow['value']);
        $this->assertSame('Baby Park & Liberty', $branchRow['value']);
        $this->assertSame(ShopCustomization::SOURCE_BUSINESS, $mainRow['source']);
        $this->assertSame(ShopCustomization::SOURCE_BUSINESS, $branchRow['source']);
    }

    public function test_a_never_customized_shop_resolves_to_exact_system_defaults(): void
    {
        [$shop] = $this->createShopWithOwner();

        // the snapshot guarantee: an untouched shop's effective memo/label
        // settings ARE the defaults constants, key for key — so shipping
        // the customization layer cannot have changed any existing shop's
        // printed output
        $this->assertSame(Shop::MEMO_SETTINGS_DEFAULTS, $shop->memoSettings());
        $this->assertSame(Shop::LABEL_SETTINGS_DEFAULTS, $shop->labelSettings());

        foreach (ShopCustomization::effective($shop) as $items) {
            foreach ($items as $row) {
                $this->assertSame(
                    ShopCustomization::SOURCE_DEFAULT, $row['source'],
                    "'{$row['key']}' should be ডিফল্ট on a fresh shop, got '{$row['source']}'"
                );
            }
        }
    }

    public function test_customizing_one_shop_leaks_nothing_into_another(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner(['name' => 'Plain Shop']);

        $before = ShopCustomization::effective($shopB->fresh());

        // shop A customizes everything it can from its own settings pages
        $this->actingAs($ownerA, 'web')->patch('/app/settings/business-info', [
            'email' => 'a@a.com', 'website' => '', 'trade_license_no' => '', 'tin_no' => '',
            'label_header' => 'CUSTOM HEADER A',
        ])->assertRedirect();
        $this->actingAs($ownerA, 'web')->patch('/app/settings/memo', ['show_cashier' => false, 'paper_width' => '80mm'])->assertRedirect();
        $this->actingAs($ownerA, 'web')->patch('/app/settings/label', ['per_row' => 1, 'show_product_name' => false])->assertRedirect();

        // shop B's effective settings: byte-identical to before
        $this->assertSame($before, ShopCustomization::effective($shopB->fresh()));

        // and through the real HTTP layer, B's shared shop prop still
        // resolves B's own name as its label header
        $this->actingAs($ownerB, 'web')->get('/app/more')->assertInertia(fn ($page) => $page
            ->where('shop.resolved_label_header', 'Plain Shop')->etc());
    }

    public function test_admin_impersonation_sees_exactly_what_the_owner_sees(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['label_header' => 'Baby Park & Liberty']);
        $admin = $this->superAdmin();

        $ownerView = $this->actingAs($owner, 'web')->get('/app/more');
        $ownerView->assertInertia(fn ($page) => $page->where('shop.resolved_label_header', 'Baby Park & Liberty')->etc());
        auth('web')->logout();

        // impersonation goes through the REAL route (logs in as the owner
        // on the web guard), then reads the same page
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate")->assertRedirect();
        $this->get('/app/more')->assertInertia(fn ($page) => $page
            ->where('shop.resolved_label_header', 'Baby Park & Liberty')
            ->where('shop.id', $shop->id)->etc());
    }

    public function test_effective_settings_command_prints_sources_and_validates_all_shops(): void
    {
        [$shop] = $this->createShopWithOwner(['label_header' => 'Baby Park & Liberty']);

        $this->artisan('shop:effective-settings', ['shop' => $shop->id])
            ->expectsOutputToContain('লেবেল হেডার')
            ->assertExitCode(0);

        $this->artisan('shop:effective-settings', ['--all' => true])
            ->expectsOutputToContain('সব শপের সেটিংস ঠিকমতো resolve হচ্ছে')
            ->assertExitCode(0);
    }

    public function test_super_admin_can_set_and_reset_customization_support_admin_cannot(): void
    {
        [$shop] = $this->createShopWithOwner(['memo_settings' => ['show_cashier' => false]]);
        $super = $this->superAdmin();
        $support = Admin::create(['name' => 'Support', 'email' => 'sup-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'support']);

        // support admin: can SEE (edit page renders with customization prop)
        // but both write routes are super-admin-only
        $this->actingAs($support, 'admin')->get("/admin/shops/{$shop->id}/edit")
            ->assertInertia(fn ($page) => $page->has('customization')->where('canEditCustomization', false)->etc());
        $this->actingAs($support, 'admin')->patch("/admin/shops/{$shop->id}/customization/label-header", ['label_header' => 'X'])
            ->assertForbidden();
        $this->actingAs($support, 'admin')->post("/admin/shops/{$shop->id}/customization/reset", ['key' => 'memo_settings'])
            ->assertForbidden();

        // super admin: sets the business-level header…
        $this->actingAs($super, 'admin')->patch("/admin/shops/{$shop->id}/customization/label-header", ['label_header' => 'Baby Park & Liberty'])
            ->assertRedirect();
        $this->assertSame('Baby Park & Liberty', $shop->fresh()->label_header);

        // …and the one-click reset returns a block to defaults
        $this->actingAs($super, 'admin')->post("/admin/shops/{$shop->id}/customization/reset", ['key' => 'memo_settings'])
            ->assertRedirect();
        $this->assertNull($shop->fresh()->memo_settings);
        $this->assertSame(Shop::MEMO_SETTINGS_DEFAULTS, $shop->fresh()->memoSettings());

        // an unknown key is rejected outright — reset can never touch an
        // arbitrary column
        $this->actingAs($super, 'admin')->post("/admin/shops/{$shop->id}/customization/reset", ['key' => 'cash_balance'])
            ->assertSessionHasErrors('key');
    }

    public function test_label_header_cannot_be_set_on_a_branch_from_admin(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        $super = $this->superAdmin();

        $this->actingAs($super, 'admin')->patch("/admin/shops/{$branch->id}/customization/label-header", ['label_header' => 'Nope'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($branch->fresh()->label_header);
    }

    public function test_app_version_endpoint_returns_the_current_build_version(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->getJson('/app/app-version');
        $response->assertOk();
        $this->assertNotSame('', (string) $response->json('v'));

        // and the shared prop every page carries matches it — what the
        // banner's mismatch comparison relies on
        $this->actingAs($owner, 'web')->get('/app/more')->assertInertia(fn ($page) => $page
            ->where('assetVersion', $response->json('v'))->etc());
    }
}
