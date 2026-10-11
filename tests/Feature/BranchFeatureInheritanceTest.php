<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part of tonight's branch-audit follow-up: a feature newly granted to a
 * MAIN shop used to only ever reach branches at creation time (a one-time
 * copy in ShopController::createBranch) — enabling a feature on the main
 * shop later never reached existing branches, a real operational trap an
 * admin could easily miss.
 */
class BranchFeatureInheritanceTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'feat-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    private function updatePayload(Shop $shop, array $featureKeys): array
    {
        return [
            'name' => $shop->name, 'phone' => $shop->phone, 'business_type_id' => $shop->business_type_id,
            'sales_mode' => $shop->sales_mode, 'plan' => $shop->plan, 'lang' => $shop->lang,
            'status' => $shop->status,
            'subscription_start' => $shop->subscription_start->toDateString(),
            'subscription_expiry' => $shop->subscription_expiry->toDateString(),
            'features' => $featureKeys,
        ];
    }

    public function test_a_feature_newly_granted_to_the_main_shop_reaches_every_branch(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$main] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $branchA = Shop::create(array_merge($main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']), [
            'parent_shop_id' => $main->id, 'name' => 'Branch A', 'phone' => '019'.random_int(10000000, 99999999),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]));
        $branchB = Shop::create(array_merge($main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']), [
            'parent_shop_id' => $main->id, 'name' => 'Branch B', 'phone' => '019'.random_int(10000000, 99999999),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]));
        $feature = Feature::firstOrCreate(['key' => 'promotions'], ['label_bn' => 'x', 'label_en' => 'x', 'category' => 'general']);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->put("/admin/shops/{$main->id}", $this->updatePayload($main, ['promotions']))
            ->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertTrue($branchA->fresh()->hasFeature('promotions'));
        $this->assertTrue($branchB->fresh()->hasFeature('promotions'));
    }

    public function test_removing_a_feature_from_the_main_shop_does_not_remove_it_from_a_branch(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$main] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $branch = Shop::create(array_merge($main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']), [
            'parent_shop_id' => $main->id, 'name' => 'Branch A', 'phone' => '019'.random_int(10000000, 99999999),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]));
        $feature = Feature::firstOrCreate(['key' => 'promotions'], ['label_bn' => 'x', 'label_en' => 'x', 'category' => 'general']);
        $main->features()->syncWithoutDetaching([$feature->id]);
        $branch->features()->syncWithoutDetaching([$feature->id]); // branch already independently has it
        $admin = $this->superAdmin();

        // admin now un-ticks it on the MAIN shop only
        $this->actingAs($admin, 'admin')
            ->put("/admin/shops/{$main->id}", $this->updatePayload($main, []))
            ->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertFalse($main->fresh()->hasFeature('promotions'));
        $this->assertTrue($branch->fresh()->hasFeature('promotions')); // untouched — the branch's own grant stands
    }

    public function test_editing_a_branchs_own_features_never_propagates_anywhere(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$main] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $sibling = Shop::create(array_merge($main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']), [
            'parent_shop_id' => $main->id, 'name' => 'Sibling', 'phone' => '019'.random_int(10000000, 99999999),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]));
        $editedBranch = Shop::create(array_merge($main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']), [
            'parent_shop_id' => $main->id, 'name' => 'Edited Branch', 'phone' => '019'.random_int(10000000, 99999999),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]));
        Feature::firstOrCreate(['key' => 'promotions'], ['label_bn' => 'x', 'label_en' => 'x', 'category' => 'general']);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->put("/admin/shops/{$editedBranch->id}", $this->updatePayload($editedBranch, ['promotions']))
            ->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertTrue($editedBranch->fresh()->hasFeature('promotions'));
        $this->assertFalse($main->fresh()->hasFeature('promotions'));
        $this->assertFalse($sibling->fresh()->hasFeature('promotions'));
    }
}
