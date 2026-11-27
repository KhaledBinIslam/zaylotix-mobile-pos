<?php

namespace Tests\Feature;

use App\Models\ArticleSeriesCounter;
use App\Support\ArticleSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The serial-settings page used to look up one counter per category. counters() reads them all at once, and must
 * answer exactly what peekNext() answers for every code and band — including a band nobody has used yet.
 */
class ArticleSerialCountersMapTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_the_batched_map_answers_exactly_what_peek_next_answers(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        ArticleSeriesCounter::create(['main_shop_id' => $main->id, 'category_code' => 'B', 'series_start' => 20000, 'next_serial' => 20042]);
        ArticleSeriesCounter::create(['main_shop_id' => $main->id, 'category_code' => 'L', 'series_start' => 10000, 'next_serial' => 10007]);
        ArticleSeriesCounter::create(['main_shop_id' => $main->id, 'category_code' => 'L', 'series_start' => 90000, 'next_serial' => 90003]);

        foreach ([$main, $branch] as $shop) {
            $map = ArticleSerial::counters($shop);
            foreach ([['B', 20000], ['L', 10000], ['L', 90000], ['L', 55555], ['Z', null]] as [$code, $band]) {
                $this->assertSame(
                    ArticleSerial::peekNext($shop, $code, $band),
                    ArticleSerial::peekNext($shop, $code, $band, $map),
                    "{$code} / ".var_export($band, true).' at shop '.$shop->id,
                );
            }
        }
    }

    public function test_another_business_counters_never_appear_in_the_map(): void
    {
        [$mine] = $this->createShopWithOwner();
        [$other] = $this->createShopWithOwner();
        ArticleSeriesCounter::create(['main_shop_id' => $other->id, 'category_code' => 'B', 'series_start' => 20000, 'next_serial' => 99999]);

        $this->assertSame([], ArticleSerial::counters($mine));
        $this->assertSame(20000, ArticleSerial::peekNext($mine, 'B', 20000, ArticleSerial::counters($mine)));
    }
}
