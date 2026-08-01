<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubscriptionPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** The admin dashboard's "who's paid / who hasn't this month" lists — this is how the admin decides who to chase for payment. */
class AdminSubscriptionDashboardTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_shop_that_paid_this_month_shows_in_paid_not_unpaid(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'dash-admin@test.com', 'password' => 'password']);
        [$paidShop] = $this->createShopWithOwner(['name' => 'Paid Shop']);
        [$unpaidShop] = $this->createShopWithOwner(['name' => 'Unpaid Shop']);

        SubscriptionPayment::create([
            'shop_id' => $paidShop->id, 'plan' => 'monthly', 'amount' => 500,
            'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->where('paidShops', fn ($shops) => collect($shops)->pluck('name')->contains('Paid Shop')
                && ! collect($shops)->pluck('name')->contains('Unpaid Shop'))
            ->where('unpaidShops', fn ($shops) => collect($shops)->pluck('name')->contains('Unpaid Shop')
                && ! collect($shops)->pluck('name')->contains('Paid Shop'))
        );
    }

    public function test_paid_amount_is_summed_per_shop_for_the_month(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'dash-admin2@test.com', 'password' => 'password']);
        [$shop] = $this->createShopWithOwner(['name' => 'Two Payments Shop']);

        SubscriptionPayment::create(['shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 300, 'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString()]);
        SubscriptionPayment::create(['shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 200, 'month' => now()->format('Y-m'), 'method' => 'bkash', 'paid_on' => now()->toDateString()]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->where('paidShops', fn ($shops) => collect($shops)->firstWhere('name', 'Two Payments Shop')['paid_amount'] == 500)
        );
    }
}
