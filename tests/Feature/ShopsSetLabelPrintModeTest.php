<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsSetLabelPrintModeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_changes_only_the_print_mode_key_leaving_the_rest_of_label_settings_alone(): void
    {
        [$shop] = $this->createShopWithOwner();
        $shop->update(['label_settings' => ['print_mode' => 'thermal', 'size' => '38x25', 'per_row' => 1]]);

        $this->artisan('shops:set-label-print-mode', ['--shop' => $shop->id, '--mode' => 'classic'])->assertSuccessful();

        $shop->refresh();
        $this->assertSame('classic', $shop->label_settings['print_mode']);
        $this->assertSame('38x25', $shop->label_settings['size']);
        $this->assertSame(1, $shop->label_settings['per_row']);
    }

    public function test_works_when_label_settings_was_never_set(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('shops:set-label-print-mode', ['--shop' => $shop->id, '--mode' => 'classic'])->assertSuccessful();

        $shop->refresh();
        $this->assertSame('classic', $shop->label_settings['print_mode']);
    }

    public function test_rejects_an_invalid_mode(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('shops:set-label-print-mode', ['--shop' => $shop->id, '--mode' => 'bogus'])->assertFailed();
    }

    public function test_requires_shop_and_mode(): void
    {
        $this->artisan('shops:set-label-print-mode', [])->assertFailed();
    }
}
