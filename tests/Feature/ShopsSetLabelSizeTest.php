<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsSetLabelSizeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_sets_a_custom_size_and_leaves_other_label_settings_alone(): void
    {
        [$shop] = $this->createShopWithOwner();
        $shop->update(['label_settings' => ['size' => '38x25', 'print_mode' => 'classic', 'template' => 'classic']]);

        $this->artisan('shops:set-label-size', ['--shop' => $shop->id, '--width' => 38, '--height' => 30])
            ->assertSuccessful();

        $settings = $shop->fresh()->label_settings;
        $this->assertSame('custom', $settings['size']);
        $this->assertEquals(38, $settings['custom_width_mm']);
        $this->assertEquals(30, $settings['custom_height_mm']);
        $this->assertSame('classic', $settings['print_mode']);
        $this->assertSame('classic', $settings['template']);
    }

    public function test_requires_shop_width_and_height(): void
    {
        $this->artisan('shops:set-label-size')->assertFailed();
    }

    public function test_reports_an_unknown_shop(): void
    {
        $this->artisan('shops:set-label-size', ['--shop' => 999999, '--width' => 38, '--height' => 30])
            ->assertFailed();
    }
}
