<?php

namespace Tests\Feature;

use App\Models\ArticleSeriesCounter;
use App\Models\Shop;
use App\Support\ArticleSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * docs/ARTICLE_SERIES_PLAN.md §2 — the serial counter must be shared
 * across a main shop and its branches (NOT per-shop), start at 10001, and
 * be race-safe for the very first article of a brand-new category code
 * (no pre-existing counter row).
 */
class ArticleSerialTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_first_serial_for_a_new_category_starts_at_10001(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::next($shop, 'L'));
    }

    public function test_successive_calls_increment(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::next($shop, 'L'));
        $this->assertSame(10002, ArticleSerial::next($shop, 'L'));
        $this->assertSame(10003, ArticleSerial::next($shop, 'L'));
    }

    public function test_different_category_codes_have_independent_counters(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::next($shop, 'L'));
        $this->assertSame(10001, ArticleSerial::next($shop, 'G'));
        $this->assertSame(10002, ArticleSerial::next($shop, 'L'));
    }

    public function test_a_branch_shares_the_main_shops_counter(): void
    {
        [$mainShop] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $mainShop->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);

        $this->assertSame(10001, ArticleSerial::next($mainShop, 'L'));
        // the BRANCH's own next() call continues the SAME counter, not a fresh one
        $this->assertSame(10002, ArticleSerial::next($branch, 'L'));
        $this->assertSame(10003, ArticleSerial::next($mainShop, 'L'));

        $this->assertSame(1, ArticleSeriesCounter::where('main_shop_id', $mainShop->id)->where('category_code', 'L')->count());
    }

    public function test_peek_next_does_not_reserve_or_advance_the_counter(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::peekNext($shop, 'L'));
        $this->assertSame(10001, ArticleSerial::peekNext($shop, 'L'));
        $this->assertSame(10001, ArticleSerial::next($shop, 'L'));
    }

    public function test_two_shops_never_share_a_counter_unless_one_is_the_others_branch(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        $this->assertSame(10001, ArticleSerial::next($shopA, 'L'));
        $this->assertSame(10001, ArticleSerial::next($shopB, 'L'));
    }
}
