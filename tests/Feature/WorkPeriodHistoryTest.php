<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Models\WorkPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Closing a shift always computed a variance (counted cash vs. what the
 * ledger says should be there) and saved it - but nothing in the app ever
 * showed it to anyone, so a real shortage/overage went completely unseen.
 * This adds the variance to the close toast and a history screen to browse
 * past shifts (Khaled's explicit request).
 */
class WorkPeriodHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_closing_a_shift_that_matches_the_ledger_reports_no_variance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['cash_balance' => 1000]);

        $period = WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now(),
            'opening_cash' => 500, 'cash_balance_at_open' => 1000,
        ]);

        // ledger moved +200 during the shift (1000 -> 1200); cashier counted
        // exactly 700 (500 opening + 200) - a perfect match
        $shop->update(['cash_balance' => 1200]);

        $response = $this->actingAs($owner, 'web')
            ->post("/app/work-period/{$period->id}/close", ['closing_cash' => 700]);

        $this->assertSame(0.0, (float) $period->fresh()->variance);
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'মিলেছে'));
    }

    public function test_closing_a_shift_short_of_the_ledger_reports_a_shortage(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['cash_balance' => 1000]);

        $period = WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now(),
            'opening_cash' => 500, 'cash_balance_at_open' => 1000,
        ]);

        // ledger says the drawer should have grown by +200 (500 -> 700), but
        // only 550 was actually counted (change of +50) - ৳150 short
        $shop->update(['cash_balance' => 1200]);

        $response = $this->actingAs($owner, 'web')
            ->post("/app/work-period/{$period->id}/close", ['closing_cash' => 550]);

        $this->assertSame(-150.0, (float) $period->fresh()->variance);
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'ঘাটতি') && str_contains($msg, '150'));
    }

    public function test_closing_a_shift_over_the_ledger_reports_an_overage(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['cash_balance' => 1000]);

        $period = WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now(),
            'opening_cash' => 500, 'cash_balance_at_open' => 1000,
        ]);

        $shop->update(['cash_balance' => 1200]);

        $response = $this->actingAs($owner, 'web')
            ->post("/app/work-period/{$period->id}/close", ['closing_cash' => 750]);

        $this->assertSame(50.0, (float) $period->fresh()->variance);
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'বাড়তি') && str_contains($msg, '50'));
    }

    public function test_history_lists_closed_shifts_newest_first_with_the_opener(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $older = WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now()->subDays(2),
            'opening_cash' => 500, 'cash_balance_at_open' => 1000,
            'closed_at' => now()->subDays(2)->addHours(8), 'closing_cash' => 700, 'cash_balance_at_close' => 1200, 'variance' => 0,
        ]);
        $newer = WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now()->subHours(8),
            'opening_cash' => 400, 'cash_balance_at_open' => 900,
            'closed_at' => now(), 'closing_cash' => 300, 'cash_balance_at_close' => 950, 'variance' => -50,
        ]);
        // never closed - must not show up in history
        WorkPeriod::create([
            'shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now(),
            'opening_cash' => 200, 'cash_balance_at_open' => 950,
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/work-period/history');

        $response->assertOk();
        $periods = $response->getOriginalContent()->getData()['page']['props']['periods']['data'];
        $this->assertCount(2, $periods);
        $this->assertSame($newer->id, $periods[0]['id']);
        $this->assertSame($older->id, $periods[1]['id']);
        $this->assertSame($owner->name, $periods[0]['opened_by_user']['name']);
    }

    public function test_history_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        WorkPeriod::create([
            'shop_id' => $shopB->id, 'opened_by' => $ownerB->id, 'opened_at' => now(),
            'opening_cash' => 500, 'cash_balance_at_open' => 1000,
            'closed_at' => now(), 'closing_cash' => 500, 'cash_balance_at_close' => 1000, 'variance' => 0,
        ]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/work-period/history');

        $periods = $response->getOriginalContent()->getData()['page']['props']['periods']['data'];
        $this->assertCount(0, $periods);
    }
}
