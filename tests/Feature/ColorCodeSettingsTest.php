<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** docs/ARTICLE_SERIES_PLAN.md §9 — the editable রং-কোড list, uniqueness-checked within one shop. */
class ColorCodeSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_saves_the_color_code_map(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->patch('/app/articles/color-code-settings', [
            'rows' => [['color' => 'SILVER', 'code' => 'SIL'], ['color' => 'KHAKI', 'code' => 'KHK']],
            'barcode_include_size' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(['SILVER' => 'SIL', 'KHAKI' => 'KHK'], $shop->fresh()->color_code_map);
        $this->assertTrue($shop->fresh()->barcode_include_size);
    }

    public function test_rejects_two_colors_sharing_the_same_code(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->patch('/app/articles/color-code-settings', [
            'rows' => [['color' => 'SILVER', 'code' => 'SIL'], ['color' => 'SKY', 'code' => 'SIL']],
            'barcode_include_size' => false,
        ]);

        $response->assertSessionHasErrors('rows');
        $this->assertNull($shop->fresh()->color_code_map);
    }

    public function test_code_is_uppercased_and_trimmed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->patch('/app/articles/color-code-settings', [
            'rows' => [['color' => ' SILVER ', 'code' => ' sil ']],
            'barcode_include_size' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['SILVER' => 'SIL'], $shop->fresh()->color_code_map);
    }

    public function test_the_edit_page_shows_the_current_map(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['color_code_map' => ['SILVER' => 'SIL'], 'barcode_include_size' => true]);
        $this->grantFeature($shop, 'article_series');

        $response = $this->actingAs($owner, 'web')->get('/app/articles/color-code-settings');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('colorCodeMap.SILVER', 'SIL')
            ->where('barcodeIncludeSize', true)
        );
    }

    public function test_blocked_without_the_feature_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/app/articles/color-code-settings')->assertForbidden();
    }
}
