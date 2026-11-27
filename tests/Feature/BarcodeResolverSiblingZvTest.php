<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\BarcodeResolver;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Production, 2026-11-27 17:26: the Liberty owner scanned a ZV sticker at the branch and the scan answered HTTP 500,
 * "Maximum call stack size reached". A plain product's stored barcode IS a 'ZV{id}' string (the grid stamps it after
 * creating the row) and the branch copy carries the SAME string. The sibling fallback then looked that string up by
 * calling resolve() again — which re-read it as a short code, found the sibling again, and so on without end.
 */
class BarcodeResolverSiblingZvTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    /** @return array{0: Shop, 1: Shop, 2: ProductVariant, 3: ProductVariant} main, branch, main variant, branch copy */
    private function aPlainProductAtBothShops(): array
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        $make = function (Shop $shop, ?string $barcode = null) {
            $p = Product::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'Bag', 'cost' => 50, 'price' => 100, 'stock' => 4]);

            return ProductVariant::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Red', 'stock' => 4, 'barcode' => $barcode]);
        };
        $mainVariant = $make($main);
        // the opening-stock grid stamps the plain-product barcode AFTER the row exists: 'ZV' . id
        $mainVariant->update(['barcode' => 'ZV'.$mainVariant->id]);
        // the pooled/copied branch row carries the very same string (CatalogSync keeps barcodes identical across shops)
        $branchVariant = $make($branch, 'ZV'.$mainVariant->id);

        return [$main, $branch, $mainVariant, $branchVariant];
    }

    private function actAt(Shop $shop): void
    {
        Tenancy::set($shop->id);
        Tenancy::forgetShopCache();
    }

    public function test_a_main_shop_zv_sticker_scanned_at_the_branch_finds_the_branch_copy_instead_of_recursing(): void
    {
        [, $branch, $mainVariant, $branchVariant] = $this->aPlainProductAtBothShops();
        $this->actAt($branch);

        $r = BarcodeResolver::resolve('ZV'.$mainVariant->id);

        $this->assertTrue($r['found'], 'the sticker resolves');
        $this->assertSame($branchVariant->id, $r['variant_id'], 'to THIS shop\'s own copy of the size');
        $this->assertSame($branch->id, (int) $r['product']->shop_id);
    }

    public function test_the_branchs_own_zv_sticker_still_resolves_at_the_branch(): void
    {
        [, $branch, , $branchVariant] = $this->aPlainProductAtBothShops();
        $this->actAt($branch);

        $r = BarcodeResolver::resolve('ZV'.$branchVariant->id);

        $this->assertTrue($r['found']);
        $this->assertSame($branchVariant->id, $r['variant_id']);
    }

    public function test_the_main_shops_own_zv_sticker_resolves_at_the_main_shop(): void
    {
        [$main, , $mainVariant] = $this->aPlainProductAtBothShops();
        $this->actAt($main);

        $this->assertSame($mainVariant->id, BarcodeResolver::resolve('ZV'.$mainVariant->id)['variant_id']);
    }

    public function test_a_zv_code_for_a_size_this_shop_does_not_hold_is_simply_not_found(): void
    {
        [$main, $branch, $mainVariant, $branchVariant] = $this->aPlainProductAtBothShops();
        $branchVariant->forceFill(['barcode' => 'SOMETHING-ELSE'])->save(); // the branch copy no longer carries the string
        $this->actAt($branch);

        $r = BarcodeResolver::resolve('ZV'.$mainVariant->id);

        $this->assertFalse($r['found'], 'no match and, above all, no endless loop');
    }

    public function test_a_shop_outside_the_business_never_sees_the_sibling_row(): void
    {
        [, , $mainVariant] = $this->aPlainProductAtBothShops();
        [$stranger] = $this->createShopWithOwner();
        $this->actAt($stranger);

        $this->assertFalse(BarcodeResolver::resolve('ZV'.$mainVariant->id)['found']);
    }
}
