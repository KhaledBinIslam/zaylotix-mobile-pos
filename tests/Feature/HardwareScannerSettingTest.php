<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Owner-controlled, independent of the admin-controlled camera-scan (`sales_mode`) setting — see useHardwareScanner.js for the client-side keyboard-wedge detection logic (covered by resources/js/support/__tests__/scannerBuffer.test.js instead, since PHPUnit can't exercise browser keystroke timing). */
class HardwareScannerSettingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_hardware_scanner_defaults_to_enabled(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertTrue((bool) $shop->fresh()->hardware_scanner_enabled);
    }

    public function test_owner_can_turn_the_hardware_scanner_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/hardware-scanner', [
            'hardware_scanner_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse((bool) $shop->fresh()->hardware_scanner_enabled);
    }

    public function test_owner_can_turn_it_back_on(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['hardware_scanner_enabled' => false]);

        $this->actingAs($owner, 'web')->patch('/app/settings/hardware-scanner', [
            'hardware_scanner_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue((bool) $shop->fresh()->hardware_scanner_enabled);
    }
}
