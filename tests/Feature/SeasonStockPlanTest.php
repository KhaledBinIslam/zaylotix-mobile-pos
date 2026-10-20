<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Season;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "সিজন স্টক প্ল্যানার" — see docs/FIX_PLAN.md's design note. No new source
 * of truth: the suggestion is last year's actual sale_items/sales history
 * for the same date window, nothing stored separately.
 */
class SeasonStockPlanTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithFeature(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'season_campaigns');
        $this->grantFeature($shop, 'purchases');

        return [$shop, $owner];
    }

    private function sellLastYear(int $shopId, int $productId, float $qty, string $date): void
    {
        $sale = Sale::create(['shop_id' => $shopId, 'invoice_no' => 'INV-TEST-'.uniqid(), 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'profit' => 0, 'date' => $date, 'time' => '12:00:00']);
        SaleItem::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'product_id' => $productId, 'product_name' => 'x', 'qty' => $qty, 'price' => 10, 'discount' => 0, 'cost' => 5]);
    }

    public function test_suggests_last_years_qty_minus_current_stock(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Eid Panjabi', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Eid 2027', 'starts_at' => '2027-04-01', 'expires_at' => '2027-04-10']);
        $this->sellLastYear($shop->id, $product->id, 30, '2026-04-05');

        $response = $this->actingAs($owner, 'web')->get("/app/seasons/{$season->id}/stock-plan");

        $response->assertInertia(fn ($page) => $page
            ->where('lastYearWindow.start', '2026-04-01')
            ->where('lastYearWindow.end', '2026-04-10')
            ->where('suggestions.0.qty_sold_last_year', 30)
            ->where('suggestions.0.current_stock', 10)
            ->where('suggestions.0.suggested_qty', 20) // 30 sold - 10 on hand
        );
    }

    public function test_suggested_qty_never_goes_negative_when_stock_already_exceeds_last_years_sales(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Overstocked Item', 'cost' => 100, 'price' => 200, 'stock' => 999]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Winter', 'starts_at' => '2027-01-01', 'expires_at' => '2027-01-10']);
        $this->sellLastYear($shop->id, $product->id, 5, '2026-01-05');

        $response = $this->actingAs($owner, 'web')->get("/app/seasons/{$season->id}/stock-plan");

        $response->assertInertia(fn ($page) => $page->where('suggestions.0.suggested_qty', 0));
    }

    public function test_excludes_a_product_that_has_variants(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 300, 'price' => 600, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 5]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Eid', 'starts_at' => '2027-04-01', 'expires_at' => '2027-04-10']);
        $this->sellLastYear($shop->id, $product->id, 20, '2026-04-05');

        $response = $this->actingAs($owner, 'web')->get("/app/seasons/{$season->id}/stock-plan");

        $response->assertInertia(fn ($page) => $page->has('suggestions', 0));
    }

    public function test_no_dates_on_the_season_returns_no_suggestions_without_erroring(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Undated Season']);

        $response = $this->actingAs($owner, 'web')->get("/app/seasons/{$season->id}/stock-plan");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('lastYearWindow', null)->has('suggestions', 0));
    }

    public function test_draft_purchases_creates_pending_purchases_without_touching_stock_or_balance(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $shop->update(['cash_balance' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Eid Panjabi', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Eid 2027']);

        $this->actingAs($owner, 'web')->post("/app/seasons/{$season->id}/stock-plan/draft-purchases", [
            'items' => [['product_id' => $product->id, 'qty' => 20, 'cost' => 500]],
        ])->assertRedirect(route('app.purchases.index'));

        $purchase = Purchase::first();
        $this->assertSame('pending', $purchase->status);
        $this->assertSame(20, $purchase->qty);
        $this->assertEquals(10000.0, (float) $purchase->amount); // 20 * 500
        $this->assertSame('credit', $purchase->method);
        $this->assertEquals(10.0, $product->fresh()->stock); // unchanged until received
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // unchanged until received
    }

    public function test_draft_purchases_rounds_a_fractional_qty_up_to_a_whole_number(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Loose Rice', 'cost' => 60, 'price' => 80, 'stock' => 0, 'sold_by_weight' => true]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Ramadan']);

        $this->actingAs($owner, 'web')->post("/app/seasons/{$season->id}/stock-plan/draft-purchases", [
            'items' => [['product_id' => $product->id, 'qty' => 12.4, 'cost' => 60]],
        ])->assertRedirect();

        $this->assertSame(13, Purchase::first()->qty); // ceil(12.4)
    }

    public function test_links_the_purchase_to_the_chosen_supplier(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Dhaka Wholesale']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Season']);

        $this->actingAs($owner, 'web')->post("/app/seasons/{$season->id}/stock-plan/draft-purchases", [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'cost' => 100]],
        ])->assertRedirect();

        $this->assertSame($supplier->id, Purchase::first()->supplier_id);
    }

    public function test_cannot_draft_a_purchase_against_another_shops_product(): void
    {
        [$shopA, $ownerA] = $this->shopWithFeature();
        [$shopB] = $this->shopWithFeature();
        $otherProduct = Product::create(['shop_id' => $shopB->id, 'name' => 'Other Shop Item', 'cost' => 50, 'price' => 80, 'stock' => 0]);
        $season = Season::create(['shop_id' => $shopA->id, 'name' => 'Season']);

        $this->actingAs($ownerA, 'web')->post("/app/seasons/{$season->id}/stock-plan/draft-purchases", [
            'items' => [['product_id' => $otherProduct->id, 'qty' => 5, 'cost' => 50]],
        ])->assertInvalid(['items.0.product_id']);
    }

    public function test_draft_purchases_requires_the_purchases_permission_not_just_promotions(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'season_campaigns');
        // deliberately no 'purchases' feature/permission grant
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 80, 'stock' => 0]);
        $staff = \App\Models\User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['promotions'], 'lang' => 'bn']);
        $season = Season::create(['shop_id' => $shop->id, 'name' => 'Season']);

        $this->actingAs($staff, 'web')->post("/app/seasons/{$season->id}/stock-plan/draft-purchases", [
            'items' => [['product_id' => $product->id, 'qty' => 5, 'cost' => 50]],
        ])->assertForbidden();
    }
}
