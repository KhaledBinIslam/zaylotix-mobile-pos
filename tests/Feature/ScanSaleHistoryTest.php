<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled (Baby Park, two pooled branches): scanning a sticker must say
 * WHERE and on WHICH invoice the item was sold — at this branch or the
 * sibling — and if it never was, say so plainly instead of showing
 * nothing. ReturnController::lookup() answers both: `sales` carries the
 * sale's own shop name, `resolved` carries what the sticker itself is.
 */
class ScanSaleHistoryTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function createBranch(Shop $main, string $name): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id, 'name' => $name]);
        $this->grantFeature($branch, 'returns');

        return $branch;
    }

    private function article(Shop $shop, string $size, string $barcode): array
    {
        $product = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', '80001')->first()
            ?? Product::create(['shop_id' => $shop->id, 'name' => '80001', 'barcode' => '80001-IXXZ', 'cost' => 350, 'price' => 500, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => $size, 'color' => 'WHITE-BLUE', 'barcode' => $barcode, 'stock' => 3]);

        return [$product, $variant];
    }

    private function sell(Shop $shop, Product $product, ProductVariant $variant, string $invoice, array $saleOverrides = []): Sale
    {
        $sale = Sale::create(array_merge([
            'shop_id' => $shop->id, 'invoice_no' => $invoice,
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 500, 'discount' => 0, 'total' => 500, 'profit' => 150, 'payment_mode' => 'cash',
        ], $saleOverrides));
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_name' => $product->name, 'variant_label' => $variant->label(), 'unit_factor' => 1, 'qty' => 1, 'price' => 500, 'discount' => 0, 'cost' => 350,
        ]);

        return $sale;
    }

    /** (a) sold at branch B, the sticker (ZV of B's own row) scanned at branch A -> B's invoice, with B's name on it */
    public function test_a_sticker_sold_at_the_sibling_branch_shows_that_branchs_invoice(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, 'Baby Park & Liberty');
        $branchB = $this->createBranch($main, 'লিভারটি-আগ্রাবাদ');

        [, $variantA] = $this->article($branchA, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');
        [$productB, $variantB] = $this->article($branchB, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');
        $this->sell($branchB, $productB, $variantB, 'INV-1290');

        $ownerA = User::withoutGlobalScopes()->where('shop_id', $branchA->id)->first();
        Tenancy::set($branchA->id);
        $response = $this->actingAs($ownerA, 'web')->getJson('/app/returns/lookup?q=ZV'.$variantB->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('sales'));
        $this->assertSame('INV-1290', $response->json('sales.0.invoice_no'));
        $this->assertSame('লিভারটি-আগ্রাবাদ', $response->json('sales.0.shop_name'));
        $this->assertSame('লিভারটি-আগ্রাবাদ', $response->json('sales.0.origin_shop_name'));
        $this->assertSame($variantB->id, $response->json('sales.0.items.0.product_variant_id'));
        // the sticker itself resolved to THIS branch's own row of that size
        $this->assertSame($variantA->id, $response->json('resolved.variant_id'));
        $this->assertSame('80001', $response->json('resolved.product_name'));
    }

    /** a sale at this very branch carries this branch's own name too — "kon branch-e" always answered */
    public function test_a_sale_at_this_branch_carries_this_branchs_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['name' => 'Baby Park & Liberty']);
        $this->grantFeature($shop, 'returns');
        [$product, $variant] = $this->article($shop, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');
        $this->sell($shop, $product, $variant, 'INV-7');

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=ZV'.$variant->id);

        $response->assertOk();
        $this->assertSame('INV-7', $response->json('sales.0.invoice_no'));
        $this->assertSame('Baby Park & Liberty', $response->json('sales.0.shop_name'));
        $this->assertNull($response->json('sales.0.origin_shop_name'));
    }

    /** (b) a real sticker that was never sold: the product is recognised, the sales list is empty -> the screen says "sell hoyni" */
    public function test_a_never_sold_sticker_is_recognised_with_no_sales(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        [, $variant] = $this->article($shop, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=ZV'.$variant->id);

        $response->assertOk();
        $this->assertSame([], $response->json('sales'));
        $this->assertSame('80001', $response->json('resolved.product_name'));
        $this->assertSame($variant->id, $response->json('resolved.variant_id'));
        $this->assertStringContainsString('40-41', (string) $response->json('resolved.variant_label'));

        // an unknown code is a different answer: nothing resolved at all
        $unknown = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=NO-SUCH-CODE');
        $unknown->assertOk();
        $this->assertSame([], $unknown->json('sales'));
        $this->assertNull($unknown->json('resolved'));
    }

    /** (c) a voided bill is not "sold" */
    public function test_a_voided_sale_is_not_listed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        [$product, $variant] = $this->article($shop, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');
        $this->sell($shop, $product, $variant, 'INV-VOID', ['voided_at' => now()]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=ZV'.$variant->id);

        $response->assertOk();
        $this->assertSame([], $response->json('sales'));
        $this->assertSame($variant->id, $response->json('resolved.variant_id'));
    }

    /** (d) size precision: only 36-37 was sold; scanning the 40-41 sticker of the same article is "not sold" */
    public function test_scanning_an_unsold_size_of_a_sold_article_is_not_sold(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        [$product, $sold] = $this->article($shop, '36-37', 'CHINA-S-80001-IXXZ-WHITE-BLUE-36-37');
        [, $unsold] = $this->article($shop, '40-41', 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41');
        $this->sell($shop, $product, $sold, 'INV-36');

        $hit = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=ZV'.$sold->id);
        $this->assertSame('INV-36', $hit->json('sales.0.invoice_no'));

        $miss = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=ZV'.$unsold->id);
        $miss->assertOk();
        $this->assertSame([], $miss->json('sales'), 'a sibling size of the same article must not count as this size being sold');
        $this->assertSame($unsold->id, $miss->json('resolved.variant_id'));

        // the article's own (product-level) barcode is deliberately broad: any size sold counts
        $article = $this->actingAs($owner, 'web')->getJson('/app/returns/lookup?q=80001-IXXZ');
        $this->assertSame('INV-36', $article->json('sales.0.invoice_no'));
        $this->assertNull($article->json('resolved.variant_id'));
    }
}
