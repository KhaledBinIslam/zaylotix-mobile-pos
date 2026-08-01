<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class CashLedgerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_deposit_increases_cash_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', [
            'type' => 'deposit', 'amount' => 500,
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash + 500, (float) $shop->cash_balance);
        $this->assertSame('deposit', CashTransaction::first()->type);
    }

    public function test_withdraw_decreases_cash_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', [
            'type' => 'withdraw', 'amount' => 200,
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash - 200, (float) $shop->cash_balance);
    }

    public function test_cash_to_bank_moves_between_both_balances(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $startCash = (float) $shop->cash_balance;
        $startBank = (float) $shop->bank_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', [
            'type' => 'cash_to_bank', 'amount' => 300,
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash - 300, (float) $shop->cash_balance);
        $this->assertEquals($startBank + 300, (float) $shop->bank_balance);
    }

    public function test_bank_to_cash_moves_between_both_balances(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $shop->update(['bank_balance' => 1000]);
        $startCash = (float) $shop->cash_balance;

        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', [
            'type' => 'bank_to_cash', 'amount' => 400,
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals($startCash + 400, (float) $shop->cash_balance);
        $this->assertEquals(600.0, (float) $shop->bank_balance);
    }

    public function test_bank_to_bank_transfer_does_not_change_total_bank_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $shop->update(['bank_balance' => 1000]);

        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', [
            'type' => 'bank_to_bank', 'amount' => 250, 'from_label' => 'DBBL', 'to_label' => 'IFIC',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertEquals(1000.0, (float) $shop->bank_balance);
        $transaction = CashTransaction::first();
        $this->assertSame('DBBL', $transaction->from_label);
        $this->assertSame('IFIC', $transaction->to_label);
    }

    public function test_ledger_index_lists_transactions(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        $this->actingAs($owner, 'web')->post('/app/accounts/ledger', ['type' => 'deposit', 'amount' => 100]);

        $response = $this->actingAs($owner, 'web')->get('/app/accounts/ledger');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.type', 'deposit')
        );
    }

    public function test_transactions_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'accounts');
        $this->grantFeature($shopB, 'accounts');

        $this->actingAs($ownerA, 'web')->post('/app/accounts/ledger', ['type' => 'deposit', 'amount' => 100]);
        $this->actingAs($ownerB, 'web')->post('/app/accounts/ledger', ['type' => 'deposit', 'amount' => 200]);

        $this->assertSame(1, CashTransaction::forShop($shopA->id)->count());
        $this->assertSame(1, CashTransaction::forShop($shopB->id)->count());
    }
}
