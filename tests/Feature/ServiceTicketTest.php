<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The repair/service-job flow end to end: intake, add parts (real stock,
 * taken immediately) and labor (free-typed, no stock), remove a line,
 * cancel (restocks), and bill — which must produce a completely ordinary
 * Sale (sale_type='service') with cash_balance/customer due updated exactly
 * like any other checkout, since nothing downstream should need to know a
 * sale came from a service ticket rather than the POS.
 */
class ServiceTicketTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_a_ticket_links_the_customer_and_the_imei(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'iPhone 12', 'cost' => 50000, 'price' => 70000, 'stock' => 1]);
        $serial = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '123456', 'status' => 'sold']);

        $response = $this->actingAs($owner, 'web')->post(route('app.serviceTickets.store'), [
            'device_label' => 'iPhone 12 — cracked screen',
            'imei' => '123456',
            'problem_description' => 'Screen cracked',
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ]);

        $ticket = ServiceTicket::first();
        $response->assertRedirect(route('app.serviceTickets.show', $ticket->id));
        $this->assertSame($product->id, $ticket->product_id);
        $this->assertSame($serial->id, $ticket->product_serial_id);
        $this->assertSame('Karim', $ticket->customer->name);
        $this->assertSame('received', $ticket->status);
    }

    public function test_adding_a_part_takes_stock_immediately(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 5]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), [
            'product_id' => $screen->id, 'qty' => 1, 'price' => 3500,
        ]);

        $response->assertRedirect();
        $this->assertEquals(4, $screen->fresh()->stock);
        $item = ServiceTicketItem::first();
        $this->assertSame('Screen Part', $item->label);
        $this->assertEquals(2000, $item->cost);
    }

    public function test_adding_a_labor_line_never_touches_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), [
            'is_labor' => true, 'label' => 'Screen replacement labor', 'qty' => 1, 'price' => 500,
        ]);

        $response->assertRedirect();
        $item = ServiceTicketItem::first();
        $this->assertTrue($item->is_labor);
        $this->assertNull($item->product_id);
        $this->assertNull($item->cost);
    }

    public function test_cannot_add_a_part_beyond_its_own_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 1]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->postJson(route('app.serviceTickets.items.store', $ticket->id), [
            'product_id' => $screen->id, 'qty' => 5, 'price' => 3500,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, $screen->fresh()->stock);
    }

    public function test_removing_an_unbilled_part_restocks_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 5]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'received', 'received_at' => now()]);
        $item = ServiceTicketItem::create(['shop_id' => $shop->id, 'service_ticket_id' => $ticket->id, 'product_id' => $screen->id, 'label' => 'Screen Part', 'qty' => 1, 'cost' => 2000, 'price' => 3500]);
        $screen->decrement('stock', 1);

        $response = $this->actingAs($owner, 'web')->delete(route('app.serviceTickets.items.destroy', $item->id));

        $response->assertRedirect();
        $this->assertEquals(5, $screen->fresh()->stock);
        $this->assertSame(0, ServiceTicketItem::count());
    }

    public function test_cancelling_a_ticket_restocks_every_unbilled_part(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 5]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'in_progress', 'received_at' => now()]);
        ServiceTicketItem::create(['shop_id' => $shop->id, 'service_ticket_id' => $ticket->id, 'product_id' => $screen->id, 'label' => 'Screen Part', 'qty' => 2, 'cost' => 2000, 'price' => 3500]);
        $screen->decrement('stock', 2);

        $response = $this->actingAs($owner, 'web')->post(route('app.serviceTickets.cancel', $ticket->id));

        $response->assertRedirect();
        $this->assertEquals(5, $screen->fresh()->stock);
        $this->assertSame('cancelled', $ticket->fresh()->status);
    }

    public function test_billing_a_ticket_creates_an_ordinary_service_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'service_billing');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0]);
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 5]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'device_label' => 'Phone', 'status' => 'ready', 'received_at' => now()]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), ['product_id' => $screen->id, 'qty' => 1, 'price' => 3500]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), ['is_labor' => true, 'label' => 'Labor', 'qty' => 1, 'price' => 500]);

        $response = $this->actingAs($owner, 'web')->post(route('app.serviceTickets.bill', $ticket->id), [
            'payments' => [['method' => 'cash', 'amount' => 4000]],
        ]);

        $response->assertRedirect();
        $sale = Sale::first();
        $this->assertSame('service', $sale->sale_type);
        $this->assertEquals(4000, (float) $sale->total);
        $this->assertEquals(2000, (float) $sale->profit); // (3500-2000) + (500-0)
        $this->assertEquals(4000, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(2, $sale->items()->count());

        $ticket->refresh();
        $this->assertSame('delivered', $ticket->status);
        $this->assertSame($sale->id, $ticket->sale_id);
        $this->assertNotNull($ticket->delivered_at);
    }

    /** Phase 5 — see docs/FIX_PLAN.md. Confirmed bug: show() always scoped items to whereNull('sale_id'), so a delivered ticket's own page showed an empty item list and ৳0 total for work that was genuinely billed. */
    public function test_a_delivered_tickets_own_page_still_shows_its_billed_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'service_billing');
        $screen = Product::create(['shop_id' => $shop->id, 'name' => 'Screen Part', 'cost' => 2000, 'price' => 3500, 'stock' => 5]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'ready', 'received_at' => now()]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), ['product_id' => $screen->id, 'qty' => 1, 'price' => 3500]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.bill', $ticket->id), ['payments' => [['method' => 'cash', 'amount' => 3500]]]);

        $response = $this->actingAs($owner, 'web')->get(route('app.serviceTickets.show', $ticket->id));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('ticket.items', 1)
            ->where('ticket.items.0.price', '3500.00')
        );
    }

    public function test_billing_on_credit_increases_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'device_label' => 'Phone', 'status' => 'ready', 'received_at' => now()]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), ['is_labor' => true, 'label' => 'Labor', 'qty' => 1, 'price' => 1000]);

        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.bill', $ticket->id), ['payments' => []])->assertRedirect();

        $this->assertEquals(1000, (float) $customer->fresh()->due);
    }

    public function test_cannot_bill_a_ticket_with_no_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'ready', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->postJson(route('app.serviceTickets.bill', $ticket->id), ['payments' => []]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_cannot_bill_an_already_delivered_ticket_twice(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Phone', 'status' => 'ready', 'received_at' => now()]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.items.store', $ticket->id), ['is_labor' => true, 'label' => 'Labor', 'qty' => 1, 'price' => 1000]);
        $this->actingAs($owner, 'web')->post(route('app.serviceTickets.bill', $ticket->id), ['payments' => [['method' => 'cash', 'amount' => 1000]]])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->postJson(route('app.serviceTickets.bill', $ticket->id), ['payments' => []]);

        $response->assertStatus(422);
        $this->assertSame(1, Sale::count());
    }

    public function test_history_shows_previous_tickets_for_the_same_customer_not_everyone_elses(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        $karim = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0]);
        $rahim = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01722222222', 'due' => 0]);
        $oldTicket = ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $karim->id, 'device_label' => 'Old Phone', 'status' => 'delivered', 'received_at' => now()->subMonth()]);
        ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $rahim->id, 'device_label' => 'Someone Elses Phone', 'status' => 'received', 'received_at' => now()]);
        $newTicket = ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $karim->id, 'device_label' => 'New Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get(route('app.serviceTickets.show', $newTicket->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('history', 1)
            ->where('history.0.id', $oldTicket->id));
    }

    public function test_history_is_empty_for_a_walk_in_with_no_customer_or_serial(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'service_billing');
        ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Some Phone', 'status' => 'received', 'received_at' => now()]);
        $ticket = ServiceTicket::create(['shop_id' => $shop->id, 'device_label' => 'Another Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get(route('app.serviceTickets.show', $ticket->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('history', 0));
    }

    public function test_tickets_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'service_billing');
        [$shopB] = $this->createShopWithOwner();
        $foreignTicket = ServiceTicket::create(['shop_id' => $shopB->id, 'device_label' => 'Other Shops Phone', 'status' => 'received', 'received_at' => now()]);

        $response = $this->actingAs($ownerA, 'web')->get(route('app.serviceTickets.show', $foreignTicket->id));

        $response->assertStatus(404);
    }
}
