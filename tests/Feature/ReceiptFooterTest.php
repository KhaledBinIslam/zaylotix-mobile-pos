<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ReceiptFooterTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_set_a_custom_receipt_footer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/receipt-footer', [
            'receipt_footer' => 'আবার আসবেন, ধন্যবাদ শপিং করার জন্য!',
        ])->assertRedirect();

        $this->assertSame('আবার আসবেন, ধন্যবাদ শপিং করার জন্য!', $shop->fresh()->receipt_footer);
    }

    public function test_receipt_footer_can_be_cleared_back_to_the_default(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['receipt_footer' => 'Custom text']);

        $this->actingAs($owner, 'web')->patch('/app/settings/receipt-footer', [
            'receipt_footer' => '',
        ])->assertRedirect();

        $this->assertNull($shop->fresh()->receipt_footer);
    }

    public function test_receipt_footer_is_too_long_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/receipt-footer', [
            'receipt_footer' => str_repeat('a', 300),
        ])->assertSessionHasErrors('receipt_footer');
    }
}
