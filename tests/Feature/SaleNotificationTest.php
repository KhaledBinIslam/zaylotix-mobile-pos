<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\SaleMade;
use App\Support\BillingPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "sells holei j branch e hoq baby park er 2 ta branch e owner
 * [ke] sathe sathe notification and mail jai moto" — every sale, from
 * either branch, reaches the business owner's bell and a configured
 * email. Off by default, business-wide, and a preview must never send
 * one.
 */
class SaleNotificationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function checkoutPayload(Product $product): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => (float) $product->price]],
        ];
    }

    private function aShopWithAProduct(array $shopAttrs = []): array
    {
        [$shop, $owner] = $this->createShopWithOwner($shopAttrs);
        $product = Product::create([
            'shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 10,
        ]);

        return [$shop, $owner, $product];
    }

    public function test_no_notification_when_the_business_never_turned_it_on(): void
    {
        Notification::fake();
        [$shop, $owner, $product] = $this->aShopWithAProduct();

        $this->actingAs($owner, 'web')->postJson(route('app.pos.checkout'), $this->checkoutPayload($product))
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_owner_gets_a_bell_notification_when_turned_on(): void
    {
        Notification::fake();
        [$shop, $owner, $product] = $this->aShopWithAProduct(['notify_owner_on_sale' => true]);

        $this->actingAs($owner, 'web')->postJson(route('app.pos.checkout'), $this->checkoutPayload($product))
            ->assertOk();

        Notification::assertSentTo($owner, SaleMade::class);
    }

    public function test_configured_email_gets_the_mail(): void
    {
        Notification::fake();
        [$shop, $owner, $product] = $this->aShopWithAProduct([
            'sale_notification_email' => 'boss@babypark.com',
        ]);

        $this->actingAs($owner, 'web')->postJson(route('app.pos.checkout'), $this->checkoutPayload($product))
            ->assertOk();

        Notification::assertSentOnDemand(
            SaleMade::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'boss@babypark.com'
        );
    }

    public function test_a_branch_sale_notifies_the_main_shops_owner(): void
    {
        Notification::fake();
        [$main, $mainOwner] = $this->createShopWithOwner(['notify_owner_on_sale' => true]);

        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $branchUser = User::create([
            'shop_id' => $branch->id, 'name' => 'Branch Staff', 'phone' => '018'.random_int(10000000, 99999999),
            'password' => 'password', 'role' => 'owner', 'lang' => 'bn',
        ]);
        $product = Product::create([
            'shop_id' => $branch->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 5,
        ]);

        $this->actingAs($branchUser, 'web')->postJson(route('app.pos.checkout'), $this->checkoutPayload($product))
            ->assertOk();

        // the MAIN owner hears about the branch's sale — that was the ask
        Notification::assertSentTo($mainOwner, SaleMade::class);
    }

    public function test_a_billing_preview_never_notifies_anyone(): void
    {
        Notification::fake();
        [$shop, $owner, $product] = $this->aShopWithAProduct([
            'notify_owner_on_sale' => true,
            'sale_notification_email' => 'boss@babypark.com',
        ]);

        $this->actingAs($owner, 'web');
        BillingPreview::compute($this->checkoutPayload($product), $shop->id, $owner->id);

        Notification::assertNothingSent();
    }

    public function test_the_settings_save_lands_on_the_main_shop_even_from_a_branch(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $branchUser = User::create([
            'shop_id' => $branch->id, 'name' => 'Branch Owner', 'phone' => '018'.random_int(10000000, 99999999),
            'password' => 'password', 'role' => 'owner', 'lang' => 'bn',
        ]);

        $this->actingAs($branchUser, 'web')->patch(route('app.settings.saleNotification'), [
            'notify_owner_on_sale' => true,
            'sale_notification_email' => 'boss@babypark.com',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($main->fresh()->notify_owner_on_sale);
        $this->assertSame('boss@babypark.com', $main->fresh()->sale_notification_email);
        // the branch's own row stays untouched — the business reads the main's
        $this->assertFalse($branch->fresh()->notify_owner_on_sale);
    }
}
