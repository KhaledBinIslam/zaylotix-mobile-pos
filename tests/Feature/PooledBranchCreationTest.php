<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled's rule (2026-11-23, final, no exceptions): in a shared_stock_pool
 * group, a product created in ANY shop — POS quick item, plain create,
 * import, opening stock — exists in EVERY shop of the group immediately,
 * linked through master_product_id, with one identical stock figure.
 * A branch-only product in a pooled shop is never allowed.
 *
 * Carried by the Product/ProductVariant created() hooks (so no creation
 * path can be missed), which hand the work to CatalogSync's existing
 * lift/push machinery.
 */
class PooledBranchCreationTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    /** @return array{0: Shop, 1: Shop, 2: Shop} main + two branches, pool on */
    private function pooledTrio(): array
    {
        [$main] = $this->createShopWithOwner();
        [$branchA] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$branchB] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        foreach ([$main, $branchA, $branchB] as $shop) {
            $this->grantFeature($shop, 'product_variants');
        }
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        return [$main, $branchA, $branchB];
    }

    private function assertMirrored(Product $source, Shop $main, Shop ...$branches): Product
    {
        $master = $source->shop_id === $main->id
            ? $source->fresh()
            : Product::withoutGlobalScopes()->whereKey($source->fresh()->master_product_id)->first();

        $this->assertNotNull($master, 'the main shop must hold the master');
        $this->assertSame($main->id, $master->shop_id);

        foreach ($branches as $branch) {
            $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)
                ->where('master_product_id', $master->id)->whereNull('deleted_at')->first();
            $this->assertNotNull($copy, "branch #{$branch->id} must hold a linked copy");
            $this->assertEquals((float) $master->stock, (float) $copy->stock, 'one pooled figure everywhere');
        }

        return $master;
    }

    public function test_a_pos_quick_item_created_at_a_branch_exists_everywhere_immediately(): void
    {
        [$main, $branchA, $branchB] = $this->pooledTrio();

        $this->actingAs($branchA->users()->first(), 'web')
            ->postJson(route('app.pos.quickItem'), ['name' => 'হাওয়াই চপ্পল', 'price' => 120, 'qty' => 7])
            ->assertOk();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('name', 'হাওয়াই চপ্পল')->firstOrFail();
        $this->assertNotNull($branchProduct->master_product_id, 'never a branch-only product in a pooled shop');

        $master = $this->assertMirrored($branchProduct, $main, $branchA, $branchB);
        $this->assertEquals(7, (float) $master->stock);
    }

    public function test_a_plain_product_created_at_a_branch_exists_everywhere_immediately(): void
    {
        [$main, $branchA, $branchB] = $this->pooledTrio();

        $this->actingAs($branchA->users()->first(), 'web')
            ->post(route('app.products.store'), ['name' => 'Water Bottle', 'cost' => 50, 'price' => 90, 'stock' => 12])
            ->assertSessionHasNoErrors();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('name', 'Water Bottle')->firstOrFail();
        $master = $this->assertMirrored($branchProduct, $main, $branchA, $branchB);
        $this->assertEquals(12, (float) $master->stock);
    }

    public function test_an_imported_variant_product_at_a_branch_exists_everywhere_with_the_pool_figure(): void
    {
        [$main, $branchA, $branchB] = $this->pooledTrio();

        $csv = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\n"
            ."Sandal,Sandal,SND-36,Footwear,,300,550,,4,,36,Black\n"
            ."Sandal,Sandal,SND-37,Footwear,,300,550,,6,,37,Black\n";

        $this->actingAs($branchA->users()->first(), 'web')
            ->post(route('app.products.import.store'), ['file' => UploadedFile::fake()->createWithContent('import.csv', $csv)])
            ->assertSessionHasNoErrors();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('name', 'Sandal')->firstOrFail();
        $master = $this->assertMirrored($branchProduct, $main, $branchA, $branchB);
        $this->assertEquals(10, (float) $master->stock);

        // every member holds BOTH sizes with the same per-variant stock
        foreach (Product::withoutGlobalScopes()->where(fn ($q) => $q->whereKey($master->id)->orWhere('master_product_id', $master->id))->get() as $member) {
            $stocks = ProductVariant::withoutGlobalScopes()->where('product_id', $member->id)->whereNull('deleted_at')->pluck('stock', 'size');
            $this->assertEquals(4, (float) $stocks['36'], "size 36 on shop {$member->shop_id}");
            $this->assertEquals(6, (float) $stocks['37'], "size 37 on shop {$member->shop_id}");
        }
    }

    public function test_an_opening_stock_cell_added_at_a_branch_reaches_every_member(): void
    {
        [$main, $branchA, $branchB] = $this->pooledTrio();
        $this->grantFeature($main, 'opening_stock');
        $this->grantFeature($branchA, 'opening_stock');

        // a pooled article that already exists everywhere
        $master = Product::create(['shop_id' => $main->id, 'name' => 'L-30001', 'cost' => 400, 'price' => 800, 'stock' => 0]);
        $copyA = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $master->id)->firstOrFail();

        \App\Support\Tenancy::set($branchA->id);
        $this->actingAs($branchA->users()->first(), 'web')
            ->post(route('app.products.openingStock', $copyA->id), [
                'cells' => [['color' => 'RED', 'size' => '38', 'qty' => 5, 'cost' => 400]],
            ])->assertSessionHasNoErrors();

        foreach ([$master->id, $copyA->id] as $productId) {
            $v = ProductVariant::withoutGlobalScopes()->where('product_id', $productId)->whereNull('deleted_at')->first();
            $this->assertNotNull($v, "product #{$productId} must hold the new cell");
            $this->assertEquals(5, (float) $v->stock);
        }
        $this->assertEquals(5, (float) $master->fresh()->stock);
        $this->assertEquals(5, (float) $copyA->fresh()->stock);
    }

    public function test_a_variant_added_at_the_main_shop_reaches_every_branch_copy(): void
    {
        [$main, $branchA, $branchB] = $this->pooledTrio();

        $master = Product::create(['shop_id' => $main->id, 'name' => 'Belt', 'cost' => 100, 'price' => 250, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $master->id, 'size' => 'XL', 'color' => 'Brown', 'stock' => 3]);

        foreach ([$branchA, $branchB] as $branch) {
            $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $master->id)->firstOrFail();
            $v = ProductVariant::withoutGlobalScopes()->where('product_id', $copy->id)->whereNull('deleted_at')->first();
            $this->assertNotNull($v, "branch #{$branch->id} must hold the size");
            $this->assertEquals(3, (float) $v->stock);
        }
    }

    public function test_without_the_pool_feature_a_branch_creation_stays_branch_only(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog'); // catalog WITHOUT pool
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        $this->actingAs($branch->users()->first(), 'web')
            ->postJson(route('app.pos.quickItem'), ['name' => 'Candle', 'price' => 30])
            ->assertOk();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Candle')->firstOrFail();
        $this->assertNull($branchProduct->master_product_id, 'unchanged pre-existing behavior without the pool');
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Candle')->count());
    }

    /**
     * Two same-named plain rows with different barcodes are two separate
     * creations — each must get its OWN master. The name-fallback lift
     * must never double-bind one master to two copies in one shop (the
     * pool would then count that shop's stock twice). Found via the
     * plain-import path (rows without size/color take it even when the
     * shop HAS product_variants), so it is reachable in production.
     */
    public function test_two_same_named_plain_imports_get_two_masters_never_one_master_with_two_copies(): void
    {
        [$main, $branchA] = $this->pooledTrio();

        $csv = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\n"
            ."Sandal,Sandal,SND-A,Footwear,,300,550,,4,,,\n"
            ."Sandal,Sandal,SND-B,Footwear,,300,550,,6,,,\n";

        $this->actingAs($branchA->users()->first(), 'web')
            ->post(route('app.products.import.store'), ['file' => UploadedFile::fake()->createWithContent('import.csv', $csv)])
            ->assertSessionHasNoErrors();

        $branchRows = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('name', 'Sandal')->get();
        $this->assertCount(2, $branchRows);
        $this->assertCount(2, $branchRows->pluck('master_product_id')->filter()->unique(), 'each creation gets its own master');

        foreach (Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Sandal')->get() as $master) {
            $this->assertSame(1,
                Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $master->id)->whereNull('deleted_at')->count(),
                'one master never holds two live copies in one shop');
        }
    }

    /** shop_id+barcode is UNIQUE — a mirrored or propagated barcode that is already taken in the target shop is skipped, never a crash or an overwrite. */
    public function test_a_barcode_already_taken_in_a_sibling_shop_is_skipped_not_crashed(): void
    {
        [$main, $branchA] = $this->pooledTrio();

        // the branch already uses 'TAKEN-1' on an unrelated, branch-local
        // legacy row (withoutEvents: hand-built pre-hook state)
        Product::withoutEvents(function () use ($branchA) {
            $unrelated = Product::create(['shop_id' => $branchA->id, 'name' => 'Unrelated', 'cost' => 10, 'price' => 20, 'stock' => 0]);
            ProductVariant::withoutEvents(function () use ($branchA, $unrelated) {
                ProductVariant::create(['shop_id' => $branchA->id, 'product_id' => $unrelated->id, 'size' => 'X', 'color' => 'Y', 'barcode' => 'TAKEN-1', 'stock' => 1]);
                ProductVariant::create(['shop_id' => $branchA->id, 'product_id' => $unrelated->id, 'size' => 'X2', 'color' => 'Y2', 'barcode' => 'TAKEN-2', 'stock' => 1]);
            });
        });

        // (a) create-mirror collision: a main-shop variant born with that barcode
        $master = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $master->id, 'size' => '40', 'color' => 'Black', 'barcode' => 'TAKEN-1', 'stock' => 2]);

        $copyA = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $master->id)->firstOrFail();
        $mirror = ProductVariant::withoutGlobalScopes()->where('product_id', $copyA->id)->firstOrFail();
        $this->assertNull($mirror->barcode, 'taken barcode is left off the copy, same rule as CatalogSync');
        $this->assertEquals(2, (float) $mirror->stock, 'the row itself still mirrors');

        // (b) late-propagation collision: a barcode stamped after creation
        $master2 = Product::create(['shop_id' => $main->id, 'name' => 'Belt', 'cost' => 50, 'price' => 120, 'stock' => 0]);
        $mv2 = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $master2->id, 'size' => 'L', 'color' => 'Tan', 'stock' => 1]);
        $mv2->update(['barcode' => 'TAKEN-2']); // free at MAIN, already taken at the branch

        $copy2 = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $master2->id)->firstOrFail();
        $this->assertNull(ProductVariant::withoutGlobalScopes()->where('product_id', $copy2->id)->value('barcode'), 'propagation skips a taken barcode');
    }

    public function test_the_kill_switch_disables_the_hooks_without_touching_anything_else(): void
    {
        [$main, $branchA] = $this->pooledTrio();
        config(['pos.pool_auto_adopt' => false]);

        $p = Product::create(['shop_id' => $branchA->id, 'name' => 'Lamp', 'cost' => 10, 'price' => 30, 'stock' => 5]);

        $this->assertNull($p->fresh()->master_product_id, 'hooks off: creation stays shop-local, pre-hook behavior');
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Lamp')->count());
    }

    /**
     * The hooks' gate must be CHEAP for everyone else: a demo shop pays
     * one plain lookup per create (Shop::find, just to read account_type),
     * a non-pooled real shop pays two (+ hasFeature()) — never something
     * that scales with catalogue size, and never Tenancy::shop()'s
     * memoized/relation-cached instance (see creationHookShop()'s own
     * docblock for the real bug that caching shortcut caused).
     */
    public function test_demo_and_non_pooled_creates_pay_a_small_bounded_extra_not_zero(): void
    {
        [$plainShop] = $this->createShopWithOwner();          // no features at all
        [$demoShop] = $this->createShopWithOwner(['account_type' => 'demo']);

        $count = function (int $shopId, string $name) {
            \App\Support\Tenancy::set($shopId);
            // enableQueryLog(), never DB::listen()/a swapped event
            // dispatcher here -- replacing the connection's dispatcher
            // leaks into every later test in the same process (found via
            // a real cross-test failure this caused in
            // SourceLineCorrectionTest, 2026-11-23)
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $product = Product::create(['shop_id' => $shopId, 'name' => $name, 'cost' => 1, 'price' => 2, 'stock' => 0]);
            ProductVariant::create(['shop_id' => $shopId, 'product_id' => $product->id, 'size' => 'M', 'stock' => 1]);
            $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $queries;
        };

        foreach ([$plainShop, $demoShop] as $shop) {
            // warm the instance caches (features relation) exactly once,
            // the way any real request's middleware already has
            \App\Support\Tenancy::set($shop->id);
            \App\Support\Tenancy::shop()->hasFeature('anything');

            config(['pos.pool_auto_adopt' => true]);
            $with = $count($shop->id, 'With '.$shop->id);
            config(['pos.pool_auto_adopt' => false]);
            $without = $count($shop->id, 'Without '.$shop->id);

            // one Product::create + one ProductVariant::create per
            // measurement, each paying at most Shop::find + hasFeature()
            // (2 queries) through the gate -- so at most 4 extra total,
            // a small flat cost, never something that scales with the
            // shop's catalogue size
            $extra = $with - $without;
            $this->assertGreaterThanOrEqual(0, $extra);
            $this->assertLessThanOrEqual(4, $extra, "shop {$shop->id}: the gate must stay a small, bounded cost, never something that grows with catalogue size");
        }
        config(['pos.pool_auto_adopt' => true]);
    }

    public function test_demo_shops_are_never_sources_or_targets(): void
    {
        [$main, $branchA] = $this->pooledTrio();
        [$demo] = $this->createShopWithOwner(['parent_shop_id' => $main->id, 'account_type' => 'demo']);

        // a demo shop's creation never lifts into the real main
        Product::create(['shop_id' => $demo->id, 'name' => 'Demo Thing', 'cost' => 10, 'price' => 20, 'stock' => 99]);
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Demo Thing')->count());

        // a real creation never lands a copy in the demo shop
        Product::create(['shop_id' => $branchA->id, 'name' => 'Real Thing', 'cost' => 10, 'price' => 20, 'stock' => 2]);
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Real Thing')->count());
        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $main->id)->where('name', 'Real Thing')->count());
    }
}
