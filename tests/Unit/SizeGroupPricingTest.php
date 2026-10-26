<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Support\SizeGroupPricing;
use Tests\TestCase;

/**
 * docs/ARTICLE_SERIES_PLAN.md §4 — pure logic, no DB writes, same style as
 * ArticleBarcodeTest: a plain in-memory Shop is enough since
 * kidsShoeSizeGroups() just reads a cast attribute.
 */
class SizeGroupPricingTest extends TestCase
{
    private function defaultGroupPrices(): array
    {
        return ['Small' => 500, 'Medium' => 550, 'Large' => 600];
    }

    public function test_resolves_the_default_groups_by_numeric_size(): void
    {
        $shop = new Shop();

        $this->assertSame('Small', SizeGroupPricing::groupForSize($shop, '24')['label']);
        $this->assertSame('Medium', SizeGroupPricing::groupForSize($shop, '30')['label']);
        $this->assertSame('Large', SizeGroupPricing::groupForSize($shop, '36')['label']);
    }

    public function test_price_for_size_uses_the_matching_groups_price(): void
    {
        $shop = new Shop();

        $this->assertEquals(500.0, SizeGroupPricing::priceForSize($shop, '24', $this->defaultGroupPrices()));
        $this->assertEquals(550.0, SizeGroupPricing::priceForSize($shop, '29-30', $this->defaultGroupPrices()));
        $this->assertEquals(600.0, SizeGroupPricing::priceForSize($shop, '37', $this->defaultGroupPrices()));
    }

    public function test_a_size_outside_every_configured_group_resolves_to_null(): void
    {
        $shop = new Shop();

        $this->assertNull(SizeGroupPricing::groupForSize($shop, '45'));
        $this->assertNull(SizeGroupPricing::priceForSize($shop, '45', $this->defaultGroupPrices()));
    }

    public function test_a_non_numeric_size_resolves_to_null(): void
    {
        $shop = new Shop();

        $this->assertNull(SizeGroupPricing::groupForSize($shop, 'Free Size'));
    }

    public function test_the_owner_can_override_the_default_boundaries(): void
    {
        $shop = new Shop();
        $shop->kids_shoe_size_groups = [
            ['label' => 'Small', 'min_size' => 20, 'max_size' => 24],
            ['label' => 'Medium', 'min_size' => 25, 'max_size' => 29],
            ['label' => 'Large', 'min_size' => 30, 'max_size' => 34],
        ];

        $this->assertSame('Medium', SizeGroupPricing::groupForSize($shop, '26')['label']);
        // size 36 is Large under the default groups but has no match at all under the custom ones
        $this->assertNull(SizeGroupPricing::groupForSize($shop, '36'));
    }

    public function test_price_for_size_returns_null_when_the_group_has_no_price_entered(): void
    {
        $shop = new Shop();

        $this->assertNull(SizeGroupPricing::priceForSize($shop, '24', ['Medium' => 550, 'Large' => 600]));
    }
}
