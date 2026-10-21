<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 item #3 — "ব্রাঞ্চ-জুড়ে প্রোমোশন পুলিং" (see docs/FIX_PLAN.md's
 * design note). Mirrors shared catalog's own propagation shape, scoped
 * to coupon-type promotions only — a BOGO stays shop-local regardless.
 */
class SharedPromotionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    private function couponPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Eid Sale', 'type' => 'coupon', 'code' => 'EID10',
            'discount_type' => 'percent', 'discount_value' => 10,
        ], $overrides);
    }

    public function test_creating_a_coupon_on_the_main_shop_propagates_to_every_branch(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);

        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), $this->couponPayload())->assertRedirect();

        $master = Promotion::where('code', 'EID10')->first();
        $this->assertNotNull($master);

        $copyA = Promotion::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_promotion_id', $master->id)->first();
        $copyB = Promotion::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('master_promotion_id', $master->id)->first();
        $this->assertNotNull($copyA);
        $this->assertNotNull($copyB);
        $this->assertSame('EID10', $copyA->code);
        $this->assertEquals(10, (float) $copyA->discount_value);
    }

    public function test_editing_the_main_coupon_updates_every_linked_branch_copy(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), $this->couponPayload());
        $master = Promotion::where('code', 'EID10')->first();

        $this->actingAs($owner, 'web')->put(route('app.promotions.update', $master->id), $this->couponPayload(['discount_value' => 20]))->assertRedirect();

        $copy = Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_promotion_id', $master->id)->first();
        $this->assertEquals(20, (float) $copy->discount_value);
    }

    public function test_a_branchs_own_usage_count_is_never_overwritten_by_a_sync(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), $this->couponPayload());
        $master = Promotion::where('code', 'EID10')->first();
        $copy = Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_promotion_id', $master->id)->first();
        $copy->update(['used_count' => 7]);

        $this->actingAs($owner, 'web')->put(route('app.promotions.update', $master->id), $this->couponPayload(['discount_value' => 15]));

        $this->assertSame(7, $copy->fresh()->used_count);
    }

    public function test_a_branch_can_only_toggle_active_on_its_own_linked_copy(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        // in real use, granting a feature to the main shop auto-cascades to
        // every branch (see BranchFeatureInheritanceTest) -- this test's
        // cheap grantFeature() helper doesn't replicate that cascade, so
        // it's granted directly here instead
        $this->grantFeature($branch, 'promotions');
        $branchOwner = $branch->users()->first();
        $this->actingAs($main->users()->first(), 'web')->post(route('app.promotions.store'), $this->couponPayload());
        $master = Promotion::where('code', 'EID10')->first();
        $copy = Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_promotion_id', $master->id)->first();

        $this->actingAs($branchOwner, 'web')->put(route('app.promotions.update', $copy->id), $this->couponPayload(['code' => 'HACKED', 'active' => false]))->assertRedirect();

        $copy->refresh();
        $this->assertFalse($copy->active); // allowed
        $this->assertSame('EID10', $copy->code); // everything else ignored
    }

    public function test_a_branch_cannot_delete_its_own_linked_copy(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        $this->grantFeature($branch, 'promotions');
        $branchOwner = $branch->users()->first();
        $this->actingAs($main->users()->first(), 'web')->post(route('app.promotions.store'), $this->couponPayload());
        $master = Promotion::where('code', 'EID10')->first();
        $copy = Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_promotion_id', $master->id)->first();

        $this->actingAs($branchOwner, 'web')->deleteJson(route('app.promotions.destroy', $copy->id))->assertStatus(422);

        $this->assertNotNull($copy->fresh());
    }

    public function test_deleting_the_master_deletes_every_linked_branch_copy(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), $this->couponPayload());
        $master = Promotion::where('code', 'EID10')->first();

        $this->actingAs($owner, 'web')->delete(route('app.promotions.destroy', $master->id))->assertRedirect();

        $this->assertSame(0, Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->count());
    }

    public function test_a_bogo_promotion_never_propagates_even_with_the_feature_on(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'promotions');
        $this->grantFeature($main, 'shared_promotions');
        $branch = $this->createBranch($main);
        $product = \App\Models\Product::create(['shop_id' => $main->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), [
            'name' => 'B2G1', 'type' => 'bogo', 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_qty' => 1,
        ])->assertRedirect();

        $this->assertSame(0, Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->count());
    }

    public function test_no_propagation_without_the_feature_granted(): void
    {
        [$main, $owner] = $this->createShopWithOwner(); // shared_promotions not granted
        $this->grantFeature($main, 'promotions');
        $branch = $this->createBranch($main);

        $this->actingAs($owner, 'web')->post(route('app.promotions.store'), $this->couponPayload())->assertRedirect();

        $this->assertSame(0, Promotion::withoutGlobalScopes()->where('shop_id', $branch->id)->count());
    }
}
