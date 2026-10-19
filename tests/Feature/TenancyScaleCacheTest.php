<?php

namespace Tests\Feature;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Scale audit (docs/SCALING_GUIDE.md): Tenancy::shop() was re-querying the
 * `shops` table fresh on every call — HandleInertiaRequests::share() alone
 * calls it twice per request, EnsureFeatureEnabled again per feature-gated
 * route segment. Shop::hasFeature() separately claimed to be "cached
 * per-request" but called the relation QUERY BUILDER (->features()->pluck())
 * instead of the relation PROPERTY (->features), so it never actually
 * cached anything. Both fixed; these tests lock in that the fix is real
 * (same instance returned, query count stays flat on repeat calls), not
 * just that behavior still looks correct.
 */
class TenancyScaleCacheTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_shop_returns_the_same_cached_instance_within_one_request(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web');

        $first = Tenancy::shop();
        $second = Tenancy::shop();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame($shop->id, $first->id);
    }

    public function test_shop_lookup_only_queries_the_database_once_per_distinct_id(): void
    {
        [, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web');

        Tenancy::shop(); // warm the cache
        DB::enableQueryLog();
        Tenancy::shop();
        Tenancy::shop();
        Tenancy::shop();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Tenancy::shop() ran a fresh query on an already-cached id');
    }

    public function test_has_feature_only_queries_the_shop_features_join_once_per_instance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $this->actingAs($owner, 'web');

        $shop = Tenancy::shop();
        $shop->hasFeature('export'); // warms the relation
        DB::enableQueryLog();
        $this->assertTrue($shop->hasFeature('export'));
        $this->assertFalse($shop->hasFeature('whatsapp_bulk'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'hasFeature() re-queried shop_features instead of using the cached relation');
    }

    public function test_forget_shop_cache_actually_drops_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web');

        $first = Tenancy::shop();
        Tenancy::forgetShopCache();
        $second = Tenancy::shop();

        $this->assertNotSame($first, $second);
        $this->assertSame($shop->id, $second->id);
    }
}
