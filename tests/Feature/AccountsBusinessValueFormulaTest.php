<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The Accounts page's one headline figure, pinned to its definition:
 *
 *   Business Value = cash + bank + stock value + receivable (+ COD not yet
 *                    settled) - payable
 *
 * with stock value counted ONCE — a variant-bearing article is valued
 * from its sizes only (products.stock is their sum, adding both would
 * double every pair), a plain product from its own stock, and a size
 * with no cost of its own falls back to the article's cost.
 */
class AccountsBusinessValueFormulaTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_business_value_is_cash_plus_bank_plus_stock_plus_receivable_minus_payable_with_no_double_count(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 10000, 'bank_balance' => 5000, 'capital' => 8000]);
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'product_variants');
        Tenancy::set($shop->id);

        // plain product: cost 100 x stock 7 = 700
        Product::create(['shop_id' => $shop->id, 'name' => 'Plain', 'cost' => 100, 'price' => 150, 'stock' => 7]);

        // variant article: products.stock deliberately set to the sizes'
        // sum (5) -- it must NOT be added on top of the sizes' own value
        $article = Product::create(['shop_id' => $shop->id, 'name' => 'Article', 'cost' => 400, 'price' => 800, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '40', 'stock' => 2, 'cost' => 450]); // own cost: 900
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '41', 'stock' => 3, 'cost' => null]); // falls back to 400: 1200

        Customer::create(['shop_id' => $shop->id, 'name' => 'Due Guy', 'phone' => '01700000001', 'due' => 2500]);
        Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 1800]);

        $expectedStock = 700 + 900 + 1200; // 2800
        $expectedAssets = 10000 + 5000 + $expectedStock + 2500; // 20300
        $expectedNetWorth = $expectedAssets - 1800; // 18500

        $is = fn (float $expected) => fn ($actual) => (float) $actual === $expected;

        $this->actingAs($owner, 'web')->get(route('app.accounts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cash', $is(10000))
                ->where('bank', $is(5000))
                ->where('stockValue', $is($expectedStock))
                ->where('receivable', $is(2500))
                ->where('codReceivable', $is(0))
                ->where('payable', $is(1800))
                ->where('assets', $is($expectedAssets))
                ->where('liabilities', $is(1800))
                ->where('netWorth', $is($expectedNetWorth))
                ->where('retained', $is($expectedNetWorth - 8000))
            );
    }

    public function test_a_soft_deleted_size_or_article_is_not_valued(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0, 'bank_balance' => 0]);
        $this->grantFeature($shop, 'accounts');
        $this->grantFeature($shop, 'product_variants');
        Tenancy::set($shop->id);

        $article = Product::create(['shop_id' => $shop->id, 'name' => 'Article', 'cost' => 100, 'price' => 200, 'stock' => 4]);
        $live = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '40', 'stock' => 1]);
        $gone = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '41', 'stock' => 3]);
        $gone->delete();

        $deletedArticle = Product::create(['shop_id' => $shop->id, 'name' => 'Deleted', 'cost' => 999, 'price' => 1, 'stock' => 9]);
        $deletedArticle->delete();

        $this->actingAs($owner, 'web')->get(route('app.accounts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stockValue', fn ($v) => (float) $v === 100.0)); // only the live size: 100 x 1
    }
}
