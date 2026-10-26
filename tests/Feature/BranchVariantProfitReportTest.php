<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * New for Baby Park (docs/BABY_PARK_PLAN.md) — variantProfitReport() is
 * single-shop and branchProductProfitReport() is product-level only;
 * neither answers "which branch sold more of size 40" for a shared-stock-
 * pool chain, since Sale/SaleItem stays per-branch even when stock is
 * pooled. No prior coverage existed for this combination.
 */
class BranchVariantProfitReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeSaleItem(Shop $shop, string $variantLabel, float $qty, float $price, float $cost): void
    {
        $product = Product::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'Kids Sneaker', 'cost' => $cost, 'price' => $price, 'stock' => 0]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '32', 'color' => 'কালো', 'stock' => 0]);
        $sale = Sale::withoutGlobalScopes()->create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.uniqid(), 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => $qty * $price, 'total' => $qty * $price, 'payment_mode' => 'cash',
        ]);
        SaleItem::withoutGlobalScopes()->create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_variant_id' => $variant->id,
            'product_name' => 'Kids Sneaker', 'variant_label' => $variantLabel,
            'qty' => $qty, 'price' => $price, 'discount' => 0, 'cost' => $cost,
        ]);
    }

    public function test_breaks_down_variant_sales_by_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        $today = now()->toDateString();

        $this->makeSaleItem($main, '32, কালো', 3, 900, 500);
        $this->makeSaleItem($branch, '32, কালো', 5, 900, 500);

        $rows = Reports::branchVariantProfitReport([$main->id, $branch->id], $today, $today);

        $this->assertCount(2, $rows);
        $mainRow = collect($rows)->firstWhere('shop_id', $main->id);
        $branchRow = collect($rows)->firstWhere('shop_id', $branch->id);
        $this->assertSame(3.0, $mainRow['qty_sold']);
        $this->assertSame(5.0, $branchRow['qty_sold']);
        $this->assertSame(1200.0, $mainRow['profit']); // (900-500)*3
        $this->assertSame(2000.0, $branchRow['profit']); // (900-500)*5
    }

    public function test_never_leaks_another_shops_variant_sales(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$unrelated] = $this->createShopWithOwner();
        $today = now()->toDateString();

        $this->makeSaleItem($main, '32, কালো', 1, 900, 500);
        $this->makeSaleItem($unrelated, '32, কালো', 99, 900, 500);

        $rows = Reports::branchVariantProfitReport([$main->id, $branch->id], $today, $today);

        $this->assertCount(1, $rows);
        $this->assertSame($main->id, $rows[0]['shop_id']);
    }
}
