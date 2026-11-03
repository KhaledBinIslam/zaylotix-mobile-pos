<?php

namespace Tests\Feature;

use App\Models\LabelPrint;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled, after a delivery: "kongular barcode print korchi kongular
 * koreni track korle show korle valo hoi" — no way to tell which sizes
 * had their stickers printed, so whole articles were being re-printed
 * to be sure.
 */
class LabelPrintTrackingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aShopWithLabels(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');

        return [$shop, $owner];
    }

    public function test_a_run_is_recorded_against_the_barcode(): void
    {
        [$shop, $owner] = $this->aShopWithLabels();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 3, 'barcode' => 'A-36']);

        $this->actingAs($owner, 'web')->post('/app/barcode-labels/printed', [
            'rows' => [['barcode' => 'A-36', 'copies' => 3, 'product_id' => $product->id, 'product_variant_id' => $variant->id]],
        ])->assertRedirect();

        $row = LabelPrint::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('A-36', $row->barcode);
        $this->assertSame(3, $row->copies);
        $this->assertSame($variant->id, $row->product_variant_id);
    }

    public function test_the_picker_is_told_what_has_been_printed(): void
    {
        [$shop, $owner] = $this->aShopWithLabels();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 3, 'barcode' => 'A-36']);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '37', 'stock' => 1, 'barcode' => 'A-37']);

        LabelPrint::create(['shop_id' => $shop->id, 'barcode' => 'A-36', 'copies' => 3]);

        $this->actingAs($owner, 'web')->get('/app/barcode-labels')
            ->assertInertia(fn ($page) => $page
                ->where('printed.A-36.copies', 3)
                // the one never printed simply is not there, which is
                // what the page marks as needing a sticker
                ->missing('printed.A-37')
            );
    }

    public function test_a_sticker_printed_at_one_shop_counts_at_the_other(): void
    {
        [$shop, $owner] = $this->aShopWithLabels();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $shop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);

        // the branch printed it; the shoe already carries the sticker
        LabelPrint::create(['shop_id' => $branch->id, 'barcode' => 'A-36', 'copies' => 2]);

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 2, 'barcode' => 'A-36']);

        $this->actingAs($owner, 'web')->get('/app/barcode-labels')
            ->assertInertia(fn ($page) => $page->where('printed.A-36.copies', 2));
    }

    public function test_two_runs_add_up_rather_than_overwriting(): void
    {
        [$shop, $owner] = $this->aShopWithLabels();
        LabelPrint::create(['shop_id' => $shop->id, 'barcode' => 'A-36', 'copies' => 2]);
        LabelPrint::create(['shop_id' => $shop->id, 'barcode' => 'A-36', 'copies' => 5]);

        $summary = LabelPrint::summaryFor([$shop->id]);
        $this->assertSame(7, $summary['A-36']['copies']);
    }
}
