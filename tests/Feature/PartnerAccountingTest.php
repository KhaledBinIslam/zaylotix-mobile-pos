<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class PartnerAccountingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_adding_a_partner_with_investment_increases_cash_and_capital(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');
        $startCash = (float) $shop->cash_balance;
        $startCapital = (float) $shop->capital;

        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Rahim', 'ownership_percent' => 40, 'invested_amount' => 5000, 'method' => 'cash',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash + 5000, (float) $shop->cash_balance);
        $this->assertEquals($startCapital + 5000, (float) $shop->capital);
        $partner = Partner::first();
        $this->assertSame('Rahim', $partner->name);
        $this->assertEquals(40.0, (float) $partner->ownership_percent);
        $this->assertEquals(5000.0, (float) $partner->invested_amount);
    }

    public function test_ownership_percent_cannot_exceed_100_across_partners(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');

        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Rahim', 'ownership_percent' => 60,
        ])->assertRedirect();

        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Karim', 'ownership_percent' => 50,
        ])->assertSessionHasErrors('ownership_percent');

        $this->assertSame(1, Partner::count());
    }

    public function test_a_cashier_cannot_access_partner_accounts(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01800000001',
            'password' => 'password', 'role' => 'staff', 'lang' => 'bn',
        ]);

        $this->actingAs($cashier, 'web')->get('/app/accounts/partners')->assertStatus(403);
    }

    public function test_investing_more_increases_partners_invested_amount_and_shop_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');
        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Rahim', 'ownership_percent' => 40, 'invested_amount' => 5000, 'method' => 'cash',
        ]);
        $partner = Partner::first();
        $cashAfter = (float) $shop->refresh()->cash_balance;

        $this->actingAs($owner, 'web')->post("/app/accounts/partners/{$partner->id}/transactions", [
            'type' => 'investment', 'amount' => 1000, 'method' => 'cash',
        ])->assertRedirect();

        $partner->refresh();
        $shop->refresh();
        $this->assertEquals(6000.0, (float) $partner->invested_amount);
        $this->assertEquals($cashAfter + 1000, (float) $shop->cash_balance);
    }

    public function test_withdrawal_decreases_cash_and_tracks_withdrawn_amount(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');
        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Rahim', 'ownership_percent' => 40, 'invested_amount' => 5000, 'method' => 'cash',
        ]);
        $partner = Partner::first();
        $cashAfter = (float) $shop->refresh()->cash_balance;

        $this->actingAs($owner, 'web')->post("/app/accounts/partners/{$partner->id}/transactions", [
            'type' => 'withdrawal', 'amount' => 800, 'method' => 'cash',
        ])->assertRedirect();

        $partner->refresh();
        $shop->refresh();
        $this->assertEquals(800.0, (float) $partner->withdrawn_amount);
        $this->assertEquals($cashAfter - 800, (float) $shop->cash_balance);
    }

    public function test_partner_index_computes_profit_share_by_ownership_percent(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 10000, 'capital' => 10000]);
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'partners');
        // retained profit = netWorth(10000) - capital(10000) = 0 initially;
        // bump cash directly to simulate 4000 of undistributed profit
        $shop->update(['cash_balance' => 14000]);

        $this->actingAs($owner, 'web')->post('/app/accounts/partners', [
            'name' => 'Rahim', 'ownership_percent' => 25,
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/accounts/partners');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('retainedProfit', 4000)
            ->where('partners.0.profit_share', 1000)
        );
    }
}
