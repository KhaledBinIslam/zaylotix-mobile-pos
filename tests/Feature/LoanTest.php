<?php

namespace Tests\Feature;

use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_giving_a_loan_decreases_cash_balance_and_creates_a_receivable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/loans', [
            'party_name' => 'Rahim', 'type' => 'given', 'principal' => 1000, 'method' => 'cash',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash - 1000, (float) $shop->cash_balance);
        $loan = Loan::first();
        $this->assertSame('given', $loan->type);
        $this->assertEquals(1000.0, (float) $loan->outstanding);
    }

    public function test_taking_a_loan_increases_cash_balance_and_creates_a_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/loans', [
            'party_name' => 'Karim', 'type' => 'taken', 'principal' => 2000, 'method' => 'cash',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash + 2000, (float) $shop->cash_balance);
        $this->assertSame('taken', Loan::first()->type);
    }

    public function test_repaying_a_given_loan_brings_cash_back_in_and_reduces_outstanding(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->actingAs($owner, 'web')->post('/app/accounts/loans', [
            'party_name' => 'Rahim', 'type' => 'given', 'principal' => 1000, 'method' => 'cash',
        ]);
        $loan = Loan::first();
        $cashAfterLoan = (float) $shop->refresh()->cash_balance;

        $this->actingAs($owner, 'web')->post("/app/accounts/loans/{$loan->id}/payments", [
            'amount' => 400, 'method' => 'cash',
        ])->assertRedirect();

        $loan->refresh();
        $shop->refresh();
        $this->assertEquals(600.0, (float) $loan->outstanding);
        $this->assertEquals($cashAfterLoan + 400, (float) $shop->cash_balance);
    }

    public function test_repaying_a_taken_loan_sends_cash_back_out(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->actingAs($owner, 'web')->post('/app/accounts/loans', [
            'party_name' => 'Karim', 'type' => 'taken', 'principal' => 2000, 'method' => 'cash',
        ]);
        $loan = Loan::first();
        $cashAfterLoan = (float) $shop->refresh()->cash_balance;

        $this->actingAs($owner, 'web')->post("/app/accounts/loans/{$loan->id}/payments", [
            'amount' => 800, 'method' => 'cash',
        ])->assertRedirect();

        $loan->refresh();
        $shop->refresh();
        $this->assertEquals(1200.0, (float) $loan->outstanding);
        $this->assertEquals($cashAfterLoan - 800, (float) $shop->cash_balance);
    }

    public function test_overpaying_a_loan_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->actingAs($owner, 'web')->post('/app/accounts/loans', [
            'party_name' => 'Rahim', 'type' => 'given', 'principal' => 500, 'method' => 'cash',
        ]);
        $loan = Loan::first();

        $this->actingAs($owner, 'web')->post("/app/accounts/loans/{$loan->id}/payments", [
            'amount' => 600, 'method' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(500.0, (float) $loan->refresh()->outstanding);
    }

    public function test_a_loan_from_another_shop_cannot_be_paid(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'accounts');

        // Created directly (not via an HTTP request as shopA's owner) so this
        // test's only authenticated request is the one below — matching
        // TenantIsolationTest's pattern, since a prior request as a different
        // shop would leave the container's tenant binding briefly stale for
        // route-model-binding on this request (a test-only artifact; a real
        // request always starts with a fresh container).
        $loan = Loan::create(['shop_id' => $shopA->id, 'party_name' => 'Rahim', 'type' => 'given', 'principal' => 500, 'outstanding' => 500, 'method' => 'cash', 'date' => now()->toDateString()]);

        $this->actingAs($ownerB, 'web')->post("/app/accounts/loans/{$loan->id}/payments", [
            'amount' => 100, 'method' => 'cash',
        ])->assertStatus(404);
    }
}
