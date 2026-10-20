<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Promotion;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "সিজন ক্যাম্পেইন কিট" — see docs/FIX_PLAN.md's design note. A campaign is
 * a saved (promotion + segment + message) bundle; launch() only flips the
 * linked Promotion active — no new discount/messaging logic exists here
 * to test, that's PromotionTest's and the WhatsApp queue's job.
 */
class SeasonCampaignTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithFeature(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'season_campaigns');

        return [$shop, $owner];
    }

    public function test_index_lists_seasons_with_their_campaigns_and_available_promotions(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'ঈদুল ফিতর ২০২৭']);
        $promotion = Promotion::create(['shop_id' => $shop->id, 'name' => 'ঈদ অফার', 'type' => 'coupon', 'code' => 'EID10', 'discount_type' => 'percent', 'discount_value' => 10]);
        Campaign::create(['shop_id' => $shop->id, 'season_id' => $season->id, 'promotion_id' => $promotion->id, 'name' => 'ঈদ ক্যাম্পেইন', 'segment' => 'topSpenders']);

        $this->actingAs($owner, 'web')->get('/app/campaigns')->assertInertia(fn ($page) => $page
            ->has('seasons', 1)
            ->where('seasons.0.campaigns.0.name', 'ঈদ ক্যাম্পেইন')
            ->has('promotions', 1)
        );
    }

    public function test_route_requires_the_season_campaigns_feature(): void
    {
        [, $owner] = $this->createShopWithOwner(); // no grantFeature

        $this->actingAs($owner, 'web')->get('/app/campaigns')->assertForbidden();
    }

    public function test_owner_can_create_a_season(): void
    {
        [, $owner] = $this->shopWithFeature();

        $this->actingAs($owner, 'web')->post('/app/seasons', [
            'name' => 'পৌষ পার্বণ', 'tag' => 'শীত', 'starts_at' => '2027-01-10', 'expires_at' => '2027-01-20',
        ])->assertRedirect();

        $this->assertDatabaseHas('seasons', ['name' => 'পৌষ পার্বণ', 'tag' => 'শীত']);
    }

    public function test_deleting_a_season_keeps_its_campaigns_but_clears_the_season_link(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'শীত']);
        $campaign = Campaign::create(['shop_id' => $shop->id, 'season_id' => $season->id, 'name' => 'শীতের অফার', 'segment' => 'all']);

        $this->actingAs($owner, 'web')->delete("/app/seasons/{$season->id}")->assertRedirect();

        $this->assertDatabaseMissing('seasons', ['id' => $season->id]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'season_id' => null]);
    }

    public function test_owner_can_create_a_message_only_campaign_with_no_promotion(): void
    {
        [, $owner] = $this->shopWithFeature();

        $this->actingAs($owner, 'web')->post('/app/campaigns', [
            'name' => 'ফিরে আসার রিমাইন্ডার', 'segment' => 'inactive', 'message_template' => 'প্রিয় {name}, আমরা মিস করছি!',
        ])->assertRedirect();

        $this->assertDatabaseHas('campaigns', ['name' => 'ফিরে আসার রিমাইন্ডার', 'promotion_id' => null, 'segment' => 'inactive']);
    }

    public function test_a_shop_cannot_link_a_campaign_to_another_shops_season_or_promotion(): void
    {
        [$shopA, $ownerA] = $this->shopWithFeature();
        [$shopB] = $this->shopWithFeature();
        $otherSeason = Season::create(['shop_id' => $shopB->id, 'name' => "Other Shop's Season"]);
        $otherPromotion = Promotion::create(['shop_id' => $shopB->id, 'name' => 'Other', 'type' => 'coupon', 'code' => 'X', 'discount_type' => 'percent', 'discount_value' => 5]);

        $response = $this->actingAs($ownerA, 'web')->post('/app/campaigns', [
            'name' => 'Attempted cross-shop link', 'segment' => 'all',
            'season_id' => $otherSeason->id, 'promotion_id' => $otherPromotion->id,
        ]);

        $response->assertInvalid(['season_id', 'promotion_id']);
    }

    public function test_launch_activates_the_linked_promotion_and_stamps_last_launched_at(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $promotion = Promotion::create(['shop_id' => $shop->id, 'name' => 'Inactive Offer', 'type' => 'coupon', 'code' => 'OFF', 'discount_type' => 'percent', 'discount_value' => 15, 'active' => false]);
        $campaign = Campaign::create(['shop_id' => $shop->id, 'promotion_id' => $promotion->id, 'name' => 'Launch Me', 'segment' => 'all']);

        $this->actingAs($owner, 'web')->post("/app/campaigns/{$campaign->id}/launch")->assertRedirect();

        $this->assertTrue($promotion->fresh()->active);
        $this->assertNotNull($campaign->fresh()->last_launched_at);
    }

    public function test_launch_works_for_a_message_only_campaign_with_no_promotion(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $campaign = Campaign::create(['shop_id' => $shop->id, 'name' => 'No Promo', 'segment' => 'all']);

        $this->actingAs($owner, 'web')->post("/app/campaigns/{$campaign->id}/launch")->assertRedirect();

        $this->assertNotNull($campaign->fresh()->last_launched_at);
    }

    public function test_a_shop_only_sees_its_own_seasons_and_campaigns(): void
    {
        [$shopA, $ownerA] = $this->shopWithFeature();
        [$shopB] = $this->shopWithFeature();
        Season::create(['shop_id' => $shopA->id, 'name' => 'Shop A Season']);
        Season::create(['shop_id' => $shopB->id, 'name' => 'Shop B Season']);

        $this->actingAs($ownerA, 'web')->get('/app/campaigns')->assertInertia(fn ($page) => $page
            ->has('seasons', 1)
            ->where('seasons.0.name', 'Shop A Season')
        );
    }

    public function test_staff_without_promotions_permission_cannot_reach_campaigns(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $staff = \App\Models\User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->get('/app/campaigns')->assertForbidden();
    }
}
