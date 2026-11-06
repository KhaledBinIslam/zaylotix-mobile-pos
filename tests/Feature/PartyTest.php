<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\PartyStatement;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 5 — corporate parties & dues. A party is an existing
 * customer with party_type set; everything reuses the one due system.
 * A shop without the feature sees none of it, pinned below.
 */
class PartyTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aPartyShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'parties');
        Tenancy::set($shop->id);

        return [$shop, $owner];
    }

    public function test_pages_are_refused_without_the_feature(): void
    {
        [, $plainOwner] = $this->createShopWithOwner();
        $this->actingAs($plainOwner, 'web')->get(route('app.parties.index'))->assertForbidden();
    }

    public function test_party_creation_and_phone_match_converts_the_existing_customer(): void
    {
        [, $owner] = $this->aPartyShop();
        $this->actingAs($owner, 'web');

        $this->post(route('app.parties.store'), [
            'name' => 'Bashundhara', 'party_type' => 'company',
            'company_name' => 'Bashundhara Shipping Ltd', 'contact_person' => 'Karim',
        ])->assertSessionHasNoErrors();
        $this->assertSame('company', Customer::where('name', 'Bashundhara')->sole()->party_type);

        // an existing plain customer with the same phone becomes the party —
        // their due and history must follow them, never split into two rows
        $existing = Customer::create(['name' => 'Rahim Traders', 'phone' => '01700000001', 'due' => 900]);
        $this->post(route('app.parties.store'), [
            'name' => 'Rahim Traders', 'party_type' => 'company', 'phone' => '01700000001',
        ])->assertSessionHasNoErrors();

        $fresh = Customer::whereKey($existing->id)->sole();
        $this->assertSame('company', $fresh->party_type);
        $this->assertEquals(900, (float) $fresh->due);
        $this->assertSame(1, Customer::where('phone', '01700000001')->count());
    }

    public function test_statement_opening_running_balance_and_closing(): void
    {
        [$shop, $owner] = $this->aPartyShop();
        $party = Customer::create(['name' => 'Navy Mess', 'party_type' => 'company', 'due' => 300]);

        // bill of 500 fully on credit (no sale_payments rows = nothing tendered)
        Sale::create(['shop_id' => $shop->id, 'customer_id' => $party->id, 'invoice_no' => 'INV-P1', 'date' => '2026-11-02', 'time' => '10:00', 'subtotal' => 500, 'discount' => 0, 'vat' => 0, 'total' => 500, 'profit' => 100, 'payment_mode' => 'due', 'counted_in_reports' => true]);
        // then 200 collected
        Payment::create(['shop_id' => $shop->id, 'customer_id' => $party->id, 'amount' => 200, 'due_before' => 500, 'due_after' => 300, 'method' => 'cash', 'date' => '2026-11-04']);

        $stmt = PartyStatement::build($party->fresh(), '2026-11-01', '2026-11-30');
        $this->assertEquals(0, $stmt['opening']); // 300 current − (500 − 200) back-calculated
        $this->assertEquals(300, $stmt['closing']);
        $this->assertEquals([500.0, 300.0], array_column($stmt['rows'], 'balance'));

        // a range starting after the bill absorbs it into the opening figure
        $stmt = PartyStatement::build($party->fresh(), '2026-11-03', '2026-11-30');
        $this->assertEquals(500, $stmt['opening']);
        $this->assertCount(1, $stmt['rows']);
        $this->assertEquals(300, $stmt['closing']);
    }

    public function test_payment_void_restores_due_with_a_full_audit_trail(): void
    {
        [$shop, $owner] = $this->aPartyShop();
        $this->actingAs($owner, 'web');
        $party = Customer::create(['name' => 'Voidy Ltd', 'party_type' => 'company', 'due' => 1000]);

        $this->post(route('app.payments.store', $party->id), ['amount' => 400, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(600, (float) $party->fresh()->due);
        $cashAfterCollect = (float) Shop::whereKey($shop->id)->value('cash_balance');

        $payment = Payment::where('customer_id', $party->id)->sole();
        $this->post(route('app.payments.void', $payment->id))->assertSessionHasNoErrors();

        $this->assertEquals(1000, (float) $party->fresh()->due);
        $this->assertNotNull($payment->fresh()->voided_at);
        $reversal = Payment::where('void_of_payment_id', $payment->id)->sole();
        $this->assertEquals(-400, (float) $reversal->amount);
        $this->assertEquals($cashAfterCollect - 400, (float) Shop::whereKey($shop->id)->value('cash_balance'));

        // voiding the same payment twice is refused, and the reversal row itself can never be voided
        $this->post(route('app.payments.void', $payment->id))->assertSessionHasErrors();
        $this->post(route('app.payments.void', $reversal->id))->assertSessionHasErrors();
        $this->assertEquals(1000, (float) $party->fresh()->due);

        // the statement ignores the voided pair entirely — they net to zero
        $stmt = PartyStatement::build($party->fresh(), now()->startOfMonth()->toDateString(), now()->toDateString());
        $this->assertSame([], array_values(array_filter($stmt['rows'], fn ($r) => $r['type'] === 'payment')));
    }

    public function test_bank_method_credits_the_bank_balance(): void
    {
        [$shop, $owner] = $this->aPartyShop();
        $this->actingAs($owner, 'web');
        $party = Customer::create(['name' => 'Banky', 'party_type' => 'person', 'due' => 500]);

        $bankBefore = (float) Shop::whereKey($shop->id)->value('bank_balance');
        $this->post(route('app.payments.store', $party->id), ['amount' => 500, 'method' => 'bank'])->assertSessionHasNoErrors();
        $this->assertEquals($bankBefore + 500, (float) Shop::whereKey($shop->id)->value('bank_balance'));
    }

    public function test_statement_pdf_downloads_and_the_signed_public_link_works(): void
    {
        [$shop, $owner] = $this->aPartyShop();
        $this->actingAs($owner, 'web');
        $party = Customer::create(['name' => 'PDF Party', 'party_type' => 'company', 'due' => 0]);

        $resp = $this->get(route('app.parties.statementPdf', $party->id).'?from=2026-11-01&to=2026-11-30');
        $resp->assertOk();
        $this->assertSame('application/pdf', $resp->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());

        $signed = URL::temporarySignedRoute('party.publicStatement', now()->addDays(7), [
            'customer' => $party->id, 'from' => '2026-11-01', 'to' => '2026-11-30',
        ]);

        // no auth at all — the signature is the gate
        auth('web')->logout();
        $this->get($signed)->assertOk();
        $this->assertSame('application/pdf', $this->get($signed)->headers->get('Content-Type'));

        // tampering with any param kills the signature
        $this->get(str_replace('from=2026-11-01', 'from=2026-10-01', $signed))->assertForbidden();
        // and a plain unsigned URL never works
        $this->get(route('party.publicStatement', ['customer' => $party->id, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertForbidden();
    }
}
