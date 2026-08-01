<?php

namespace Tests\Feature;

use App\Console\Commands\SendPaymentReminders;
use App\Models\Admin;
use App\Notifications\AdminMessage;
use App\Notifications\PaymentDueReminder;
use App\Notifications\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_recording_a_payment_notifies_the_shops_owner(): void
    {
        Notification::fake();
        $admin = Admin::create(['name' => 'Admin', 'email' => 'notif-admin@test.com', 'password' => 'password']);
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($admin, 'admin')->post('/admin/subscriptions', [
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 500,
            'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
            'next_due' => now()->addDays(30)->toDateString(),
        ])->assertRedirect();

        Notification::assertSentTo($owner, PaymentReceived::class);
    }

    public function test_owner_can_see_and_read_their_notification(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $payment = \App\Models\SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 300,
            'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);
        $owner->notify(new PaymentReceived($payment));

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());

        $notifId = $owner->notifications()->first()->id;
        $this->actingAs($owner, 'web')->post("/app/notifications/{$notifId}/read")->assertRedirect();

        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_owner_cannot_mark_another_users_notification_as_read(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $payment = \App\Models\SubscriptionPayment::create([
            'shop_id' => $shopB->id, 'plan' => 'monthly', 'amount' => 300,
            'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);
        $ownerB->notify(new PaymentReceived($payment));
        $notifId = $ownerB->notifications()->first()->id;

        $this->actingAs($ownerA, 'web')->post("/app/notifications/{$notifId}/read")->assertNotFound();

        $this->assertSame(1, $ownerB->fresh()->unreadNotifications()->count());
    }

    public function test_payment_reminder_command_notifies_shops_expiring_soon_only_once_a_day(): void
    {
        Notification::fake();
        [$shopSoon, $ownerSoon] = $this->createShopWithOwner([
            'status' => 'active', 'subscription_expiry' => now()->addDays(2)->toDateString(),
        ]);
        [$shopFar, $ownerFar] = $this->createShopWithOwner([
            'status' => 'active', 'subscription_expiry' => now()->addDays(20)->toDateString(),
        ]);

        $this->artisan(SendPaymentReminders::class)->assertSuccessful();

        Notification::assertSentTo($ownerSoon, PaymentDueReminder::class);
        Notification::assertNotSentTo($ownerFar, PaymentDueReminder::class);
    }

    public function test_payment_reminder_command_never_sends_the_same_shop_two_reminders_in_one_day(): void
    {
        [$shop, $owner] = $this->createShopWithOwner([
            'status' => 'active', 'subscription_expiry' => now()->addDay()->toDateString(),
        ]);

        $this->artisan(SendPaymentReminders::class)->assertSuccessful();
        $this->artisan(SendPaymentReminders::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', PaymentDueReminder::class)->count());
    }

    public function test_admin_can_send_a_message_to_a_single_shops_owner(): void
    {
        Notification::fake();
        $admin = Admin::create(['name' => 'Admin', 'email' => 'notif-admin2@test.com', 'password' => 'password']);
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();

        $this->actingAs($admin, 'admin')->post('/admin/notifications', [
            'shop_id' => $shopA->id, 'message' => 'Hello shop A',
        ])->assertRedirect();

        Notification::assertSentTo($ownerA, AdminMessage::class);
        Notification::assertNotSentTo($ownerB, AdminMessage::class);
    }

    public function test_admin_can_broadcast_to_every_active_shop_when_no_shop_is_given(): void
    {
        Notification::fake();
        $admin = Admin::create(['name' => 'Admin', 'email' => 'notif-admin3@test.com', 'password' => 'password']);
        [, $ownerA] = $this->createShopWithOwner(['status' => 'active']);
        [, $ownerB] = $this->createShopWithOwner(['status' => 'active']);
        [, $ownerC] = $this->createShopWithOwner(['status' => 'inactive']);

        $this->actingAs($admin, 'admin')->post('/admin/notifications', [
            'message' => 'System maintenance tonight',
        ])->assertRedirect();

        Notification::assertSentTo($ownerA, AdminMessage::class);
        Notification::assertSentTo($ownerB, AdminMessage::class);
        Notification::assertNotSentTo($ownerC, AdminMessage::class);
    }

    public function test_shop_owner_cannot_send_admin_notifications(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/admin/notifications', ['message' => 'hack'])
            ->assertRedirect(route('admin.login'));
    }
}
