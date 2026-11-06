<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 7 — the per-shop sticker template. Baby Park's guarantee
 * lives in two places: the default is 'classic' for every existing shop,
 * and a shop without the feature can never save 'detailed' at all.
 */
class LabelTemplateTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private const PAYLOAD = [
        'size' => '38x25', 'per_row' => 2,
        'show_shop_name' => true, 'show_product_name' => true, 'show_variant' => true,
        'price_mode' => 'single', 'show_barcode_number' => true,
    ];

    public function test_every_existing_shop_defaults_to_the_classic_template(): void
    {
        [$shop] = $this->createShopWithOwner();
        $this->assertSame('classic', $shop->labelSettings()['template']);
    }

    public function test_the_feature_gates_saving_the_detailed_template(): void
    {
        // WITHOUT the feature: 'detailed' silently stays classic — a
        // hand-crafted POST can't flip a layout the shop was never shown
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->actingAs($ownerA, 'web')
            ->patch(route('app.settings.label'), self::PAYLOAD + ['template' => 'detailed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('classic', $shopA->fresh()->labelSettings()['template']);

        // WITH it: saved and merged back out of labelSettings()
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'label_templates');
        $this->actingAs($ownerB, 'web')
            ->patch(route('app.settings.label'), self::PAYLOAD + ['template' => 'detailed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('detailed', $shopB->fresh()->labelSettings()['template']);

        // an old still-open tab posting WITHOUT the key keeps the default
        $this->actingAs($ownerB, 'web')
            ->patch(route('app.settings.label'), self::PAYLOAD)
            ->assertSessionHasNoErrors();
        $this->assertSame('classic', $shopB->fresh()->labelSettings()['template']);
    }
}
