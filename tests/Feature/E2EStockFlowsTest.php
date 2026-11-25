<?php

namespace Tests\Feature;

use App\Http\Controllers\App\AccountsController;
use App\Models\ActivityLog;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockIntake;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ArticleSerial;
use App\Support\BarcodeResolver;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Does the data REALLY update?" — every stock / catalogue feature driven
 * through its real HTTP route on a Baby-Park-shaped two-branch pool, with
 * every layer asserted afterwards: product + variant rows in BOTH branches,
 * the master links, products.stock = sum of variants, the real stockValue()
 * on both shops, per-shop barcode uniqueness and BarcodeResolver, the intake
 * ledger, activity logs and supplier payable.
 */
class E2EStockFlowsTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $main;

    private Shop $branch;

    private User $mainOwner;

    private User $branchOwner;

    private ProductCategory $baby;      // main shop: BABY / B / 20000

    private ProductCategory $babyAtBranch;

    private ProductCategory $pvc;       // main shop: PVC / L / 60001

    private ProductCategory $pvcAtBranch;

    private Supplier $supplier;         // main shop supplier

    protected function setUp(): void
    {
        parent::setUp();

        [$this->main, $this->mainOwner] = $this->createShopWithOwner(['name' => 'Baby Park', 'cash_balance' => 100000]);
        [$this->branch, $this->branchOwner] = $this->createShopWithOwner(['name' => 'Liberty', 'parent_shop_id' => $this->main->id, 'cash_balance' => 100000]);
        foreach ([$this->main, $this->branch] as $s) {
            foreach (['product_variants', 'suppliers', 'article_series', 'opening_stock', 'barcode_printing', 'accounts', 'damages'] as $f) {
                $this->grantFeature($s, $f);
            }
        }
        $this->grantFeature($this->main, 'shared_catalog');
        $this->grantFeature($this->main, 'shared_stock_pool');

        $this->baby = ProductCategory::create(['shop_id' => $this->main->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B', 'serial_start' => 20000]);
        $this->babyAtBranch = ProductCategory::create(['shop_id' => $this->branch->id, 'name' => 'BABY', 'name_en' => 'BABY', 'code' => 'B', 'serial_start' => 20000]);
        $this->pvc = ProductCategory::create(['shop_id' => $this->main->id, 'name' => 'PVC', 'name_en' => 'PVC', 'code' => 'L', 'serial_start' => 60001]);
        $this->pvcAtBranch = ProductCategory::create(['shop_id' => $this->branch->id, 'name' => 'PVC', 'name_en' => 'PVC', 'code' => 'L', 'serial_start' => 60001]);

        Tenancy::set($this->main->id);
        $this->supplier = Supplier::create(['shop_id' => $this->main->id, 'name' => 'Bulu', 'short_code' => 'BUL', 'payable' => 0]);

        // B-20001 .. B-20010 already in the pool, B-20005 deleted mid-series
        for ($n = 20001; $n <= 20010; $n++) {
            $p = Product::create(['shop_id' => $this->main->id, 'category_id' => $this->baby->id, 'name' => "B-{$n}", 'name_en' => "B-{$n}", 'cost' => 100, 'price' => 200, 'stock' => 0, 'supplier_id' => $this->supplier->id]);
            ProductVariant::create(['shop_id' => $this->main->id, 'product_id' => $p->id, 'size' => '36', 'color' => 'RED', 'stock' => 2, 'barcode' => "B-{$n}-RED-36"]);
            Product::withoutGlobalScopes()->whereIn('id', $this->poolIds($p))->update(['stock' => 2]);
        }
        Product::withoutGlobalScopes()->where('shop_id', $this->main->id)->where('name', 'B-20005')->first()->delete();
        ArticleSerial::bumpFloor($this->main, 'B', 20011, 20000);
    }

    // ------------------------------------------------------------ helpers

    /** Un-pins the tenant before every request: Tenancy::set() (setUp, stockValue()) overrides the logged-in user inside the container, which a real request never has. */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();

        return parent::actingAs($user, $guard);
    }

    /** @return array<int,int> every live pool member row of the same article (master + branch copies) */
    private function poolIds(Product $p): array
    {
        $masterId = $p->master_product_id ?: $p->id;

        return Product::withoutGlobalScopes()->where(fn ($q) => $q->whereKey($masterId)->orWhere('master_product_id', $masterId))->pluck('id')->all();
    }

    private function stockValue(Shop $shop): float
    {
        Tenancy::forgetShopCache();
        Tenancy::set($shop->id);
        $m = new \ReflectionMethod(AccountsController::class, 'stockValue');
        $m->setAccessible(true);

        return (float) $m->invoke(new AccountsController);
    }

    private function rowAt(Shop $shop, string $name): ?Product
    {
        return Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $name)->whereNull('deleted_at')->first();
    }

    /** The article exists in BOTH shops, linked, identical variants + stock, products.stock = sum of variants. */
    private function assertPooledEverywhere(string $name, string $why = ''): array
    {
        $m = $this->rowAt($this->main, $name);
        $b = $this->rowAt($this->branch, $name);
        $this->assertNotNull($m, "{$why} {$name} missing at the main shop");
        $this->assertNotNull($b, "{$why} {$name} missing at the branch");
        $master = $m->master_product_id === null ? $m : $b;
        $copy = $master->id === $m->id ? $b : $m;
        $this->assertSame($master->id, (int) $copy->master_product_id, "{$name}: copy is linked to its master");

        $mv = ProductVariant::withoutGlobalScopes()->where('product_id', $m->id)->whereNull('deleted_at')->get();
        $bv = ProductVariant::withoutGlobalScopes()->where('product_id', $b->id)->whereNull('deleted_at')->get();
        $this->assertSame($mv->count(), $bv->count(), "{$why} {$name}: same number of variants in both shops");
        foreach ($mv as $v) {
            $twin = $bv->first(fn ($x) => ProductVariant::cellKey($x->color, $x->size) === ProductVariant::cellKey($v->color, $v->size));
            $this->assertNotNull($twin, "{$name}: {$v->color}/{$v->size} exists at both");
            $this->assertEquals((float) $v->stock, (float) $twin->stock, "{$name} {$v->color}/{$v->size}: identical variant stock");
        }
        if ($mv->isNotEmpty()) {
            $this->assertEquals((float) $mv->sum('stock'), (float) $m->fresh()->stock, "{$name}: main products.stock = sum of its variants");
            $this->assertEquals((float) $bv->sum('stock'), (float) $b->fresh()->stock, "{$name}: branch products.stock = sum of its variants");
        } else {
            $this->assertEquals((float) $m->fresh()->stock, (float) $b->fresh()->stock, "{$name}: identical plain stock");
        }

        return [$m->fresh(), $b->fresh()];
    }

    private function assertStockValueEqual(string $why = ''): void
    {
        $this->assertEqualsWithDelta($this->stockValue($this->main), $this->stockValue($this->branch), 0.01, "{$why} stock value must be identical in both shops");
    }

    private function assertBarcodesUniquePerShop(): void
    {
        foreach ([$this->main, $this->branch] as $s) {
            $codes = ProductVariant::withoutGlobalScopes()->where('shop_id', $s->id)->whereNull('deleted_at')->whereNotNull('barcode')->pluck('barcode');
            $this->assertSame($codes->count(), $codes->unique()->count(), "shop {$s->id}: no duplicate live barcodes");
        }
    }

    private function articlePayload(ProductCategory $category, array $over = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'category_id' => $category->id,
            'manufacturer_code' => 'BUL',
            'cost' => 650,
            'price' => 1200,
            'variants' => [
                ['color' => 'WHITE', 'size' => '36', 'qty' => 3],
                ['color' => 'WHITE', 'size' => '37', 'qty' => 2],
                ['color' => 'BLACK', 'size' => '36', 'qty' => 4],
            ],
        ], $over);
    }

    private function peek(User $owner, array $query): ?int
    {
        return $this->actingAs($owner, 'web')->getJson(route('app.articles.peekSerial', $query))->assertOk()->json('serial');
    }

    // ============================================================ 1. NEW ARTICLE

    public function test_new_article_from_the_main_shop_lands_everywhere_with_the_suggested_serial(): void
    {
        // the suggestion for BABY: B-20005 was deleted mid-series, so by design it comes back first
        $suggested = $this->peek($this->mainOwner, ['category_id' => $this->baby->id]);
        $this->assertSame(20005, $suggested, 'a deleted number inside the series is offered first (owner\'s standing rule)');

        $this->actingAs($this->mainOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->baby, ['serial' => $suggested, 'supplier_id' => $this->supplier->id]))
            ->assertSessionHasNoErrors();

        [$m, $b] = $this->assertPooledEverywhere("B-{$suggested}", 'created at main');
        $this->assertSame($suggested, (int) substr($m->name, 2), 'the serial that was suggested is the serial that was saved');
        $this->assertEquals(9, (float) $m->stock, '3+2+4 pieces');
        $this->assertSame(3, ProductVariant::withoutGlobalScopes()->where('product_id', $b->id)->whereNull('deleted_at')->count(), 'colour x size grid reached the branch (the old deleted B-20005 size stays deleted)');
        $this->assertNotNull($m->barcode);
        $this->assertBarcodesUniquePerShop();
        $this->assertStockValueEqual('after a main-shop article create');

        // every variant sticker resolves, at BOTH shops, through the real resolver
        foreach (ProductVariant::withoutGlobalScopes()->where('product_id', $m->id)->get() as $v) {
            Tenancy::set($this->main->id);
            $this->assertTrue(BarcodeResolver::resolve($v->barcode)['found'], 'variant barcode resolves at main');
            $this->assertTrue(BarcodeResolver::resolve('ZV'.$v->id)['found'], 'ZV code resolves at main');
            Tenancy::set($this->branch->id);
            $this->assertTrue(BarcodeResolver::resolve('ZV'.$v->id)['found'], 'main-shop sticker resolves at the branch (cross-branch)');
        }

        // activity log + intake ledger
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.article.create', 'shop_id' => $this->main->id]);
        $this->assertGreaterThan(0, StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->count(), 'intake ledger recorded the opening stock');
        $this->assertEquals(650 * 9, (float) StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->sum('total_cost'), 'intake value = qty x cost');
    }

    public function test_new_article_from_the_branch_lands_everywhere_with_consecutive_serials(): void
    {
        $first = $this->peek($this->branchOwner, ['category_id' => $this->babyAtBranch->id]);
        $this->actingAs($this->branchOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->babyAtBranch, ['serial' => $first]))->assertSessionHasNoErrors();

        // back-to-back: the very next suggestion must move on (never the same number twice)
        $second = $this->peek($this->branchOwner, ['category_id' => $this->babyAtBranch->id]);
        $this->assertSame(20005, $first, 'the deleted number inside the series comes first');
        $this->assertSame(20011, $second, 'once the gap is used the series continues after the last live number');
        $this->actingAs($this->branchOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->babyAtBranch, ['serial' => $second]))->assertSessionHasNoErrors();

        $third = $this->peek($this->branchOwner, ['category_id' => $this->babyAtBranch->id]);
        $this->assertSame(20012, $third, 'consecutive');
        $this->actingAs($this->branchOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->babyAtBranch, ['serial' => $third]))->assertSessionHasNoErrors();
        $this->assertSame(20013, $this->peek($this->branchOwner, ['category_id' => $this->babyAtBranch->id]), 'consecutive again');

        foreach ([$first, $second, $third] as $n) {
            [$m, $b] = $this->assertPooledEverywhere("B-{$n}", 'created at the branch');
            $this->assertEquals(9, (float) $m->stock);
        }
        $this->assertBarcodesUniquePerShop();
        $this->assertStockValueEqual('after branch-side article creates');
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.article.create', 'shop_id' => $this->branch->id]);
    }

    public function test_a_serial_that_is_already_live_is_refused_with_the_bengali_message_and_nothing_is_written(): void
    {
        $before = Product::withoutGlobalScopes()->count();

        $this->actingAs($this->mainOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->baby, ['serial' => 20003]))
            ->assertSessionHasErrors('serial');
        $this->assertStringContainsString('এখনো আছে', session('errors')->first('serial'));

        $this->assertSame($before, Product::withoutGlobalScopes()->count(), 'a refused article leaves no rows in either shop');
    }

    public function test_a_category_with_no_code_gets_its_code_saved_and_the_serial_issued(): void
    {
        $noCode = ProductCategory::create(['shop_id' => $this->main->id, 'name' => 'স্যান্ডেল', 'name_en' => 'Sandals', 'code' => null]);

        // before: no code -> no serial; with the suggested/typed code riding along -> a serial
        $this->assertNull($this->peek($this->mainOwner, ['category_id' => $noCode->id]));
        $this->assertSame(10001, $this->peek($this->mainOwner, ['category_id' => $noCode->id, 'category_code' => 'SAN']));

        $this->actingAs($this->mainOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($noCode, ['category_code' => 'SAN', 'serial' => 10001]))
            ->assertSessionHasNoErrors();

        $this->assertSame('SAN', $noCode->fresh()->code, 'the code was saved onto the category');
        $this->assertPooledEverywhere('SAN-10001', 'code-less category');
        $this->assertSame(10002, $this->peek($this->mainOwner, ['category_id' => $noCode->id]), 'and the next serial continues');
        $this->assertStockValueEqual();
    }

    public function test_the_pvc_band_runs_its_own_series_from_60001(): void
    {
        $this->assertSame(60001, $this->peek($this->mainOwner, ['category_id' => $this->pvc->id]));
        $this->actingAs($this->mainOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->pvc, ['serial' => 60001]))->assertSessionHasNoErrors();
        $this->assertPooledEverywhere('L-60001');
        $this->assertSame(60002, $this->peek($this->mainOwner, ['category_id' => $this->pvc->id]));
    }

    public function test_the_supplier_on_a_new_article_reaches_the_intake_ledger(): void
    {
        $this->actingAs($this->mainOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($this->baby, ['serial' => 20011, 'supplier_id' => $this->supplier->id]))->assertSessionHasNoErrors();

        $p = $this->rowAt($this->main, 'B-20011');
        $this->assertSame($this->supplier->id, (int) $p->supplier_id, 'article carries the supplier');
        $this->assertGreaterThan(0, StockIntake::withoutGlobalScopes()->where('product_id', $p->id)->count());
    }

    public function test_a_non_pooled_shop_runs_the_same_create_flow_without_any_mirroring(): void
    {
        [$solo, $soloOwner] = $this->createShopWithOwner(['name' => 'Solo']);
        foreach (['product_variants', 'article_series'] as $f) {
            $this->grantFeature($solo, $f);
        }
        $cat = ProductCategory::create(['shop_id' => $solo->id, 'name' => 'Ladies', 'name_en' => 'Ladies', 'code' => 'L']);

        $serial = $this->peek($soloOwner, ['category_id' => $cat->id]);
        $this->assertSame(10001, $serial);
        $this->actingAs($soloOwner, 'web')->post(route('app.articles.store'), $this->articlePayload($cat, ['serial' => $serial]))->assertSessionHasNoErrors();

        $p = Product::withoutGlobalScopes()->where('shop_id', $solo->id)->where('name', 'L-10001')->first();
        $this->assertNotNull($p);
        $this->assertSame(1, Product::withoutGlobalScopes()->where('name', 'L-10001')->count(), 'nothing was mirrored anywhere');
        $this->assertEquals(9, (float) $p->stock);
        $this->assertEquals(9, (float) ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->sum('stock'));
        $this->assertSame(10002, $this->peek($soloOwner, ['category_id' => $cat->id]));
    }

    // ============================================================ shared helpers for flows 2-5

    private function variantAt(Shop $shop, string $name, string $color, string $size, bool $withTrashed = false): ?ProductVariant
    {
        $p = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $name)->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))->first();
        if (! $p) {
            return null;
        }

        return ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))
            ->get()->first(fn ($v) => ProductVariant::cellKey($v->color, $v->size) === ProductVariant::cellKey($color, $size));
    }

    /** One pool-wide truth after any write: both shops identical + value equal + barcodes unique. */
    private function assertPoolHealthy(string $name, string $why): array
    {
        $rows = $this->assertPooledEverywhere($name, $why);
        $this->assertBarcodesUniquePerShop();
        $this->assertStockValueEqual($why);

        return $rows;
    }

    private function payable(): float
    {
        return (float) Supplier::withoutGlobalScopes()->find($this->supplier->id)->payable;
    }

    // ============================================================ 2. PLAIN PRODUCT + PRICE EDITS + DELETE/RESTORE

    public function test_a_plain_quick_item_created_at_the_main_shop_and_at_the_branch_lands_in_both(): void
    {
        $valueBefore = $this->stockValue($this->main);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.store'), [
            'name' => 'Shoe Polish', 'cost' => 40, 'price' => 60, 'stock' => 5, 'barcode' => 'POLISH-1', 'supplier_id' => $this->supplier->id,
        ])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('Shoe Polish', 'plain item created at main');
        $this->assertEquals(5, (float) $m->stock);
        $this->assertEquals(5, (float) $b->stock);
        $this->assertEquals(40, (float) $b->cost);
        $this->assertEquals(60, (float) $b->price);
        $this->assertSame('POLISH-1', $m->barcode);
        $this->assertEqualsWithDelta($valueBefore + 200, $this->stockValue($this->branch), 0.01, 'the branch sees the same +200 of stock value');
        $this->assertEquals(200, (float) StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->where('source', 'product_create')->sum('total_cost'), 'intake ledger got the opening stock');

        // and the other direction: created at the BRANCH
        $this->actingAs($this->branchOwner, 'web')->post(route('app.products.store'), [
            'name' => 'Shoe Lace', 'cost' => 10, 'price' => 25, 'stock' => 3,
        ])->assertSessionHasNoErrors();
        [$m2, $b2] = $this->assertPoolHealthy('Shoe Lace', 'plain item created at the branch');
        $this->assertEquals(3, (float) $m2->stock);
        $this->assertEquals(3, (float) $b2->stock);
        $this->assertNotNull($m2->master_product_id === null ? $b2->master_product_id : $m2->master_product_id, 'one of the two is linked to the other');
    }

    public function test_price_and_cost_edits_reach_the_other_shop_the_way_they_are_designed_to(): void
    {
        // a MAIN-shop edit of the article (products.update) owns the shared fields -> copied to the branch
        $m = $this->rowAt($this->main, 'B-20001');
        $this->actingAs($this->mainOwner, 'web')->put(route('app.products.update', $m), [
            'name' => 'B-20001', 'cost' => 110, 'price' => 260, 'stock' => 2,
        ])->assertSessionHasNoErrors();
        $b = $this->rowAt($this->branch, 'B-20001');
        $this->assertEquals(260, (float) $b->price, 'main price edit reaches the branch copy');
        $this->assertEquals(110, (float) $b->cost, 'main cost edit reaches the branch copy');
        $this->assertEquals(2, (float) $b->stock, 'and the stock stays the pooled 2');

        // a single-variant price edit (updatePrice): only that variant row, never stock, with its activity log
        $mv = $this->variantAt($this->main, 'B-20001', 'RED', '36');
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.updatePrice', $mv), ['cost' => 120, 'price' => 300])->assertSessionHasNoErrors();
        $mv = $mv->fresh();
        $this->assertEquals(120, (float) $mv->cost);
        $this->assertEquals(300, (float) $mv->price);
        $this->assertEquals(2, (float) $mv->stock, 'a price edit never touches stock');
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.variant.priceUpdate', 'shop_id' => $this->main->id]);

        $bv = $this->variantAt($this->branch, 'B-20001', 'RED', '36');
        $this->assertEquals(
            (float) $mv->cost, (float) $bv->cost,
            'POOL TRUTH: the same shoe must carry the same cost in both shops, otherwise the two stock values disagree'
        );
        $this->assertEquals((float) $mv->price, (float) $bv->price, 'the same shoe sells at the same price in both shops');
        $this->assertStockValueEqual('after a variant cost edit');
    }

    public function test_deleting_and_restoring_a_product_cascades_to_the_other_shop_both_ways(): void
    {
        // delete at the BRANCH -> the main copy goes too
        $b = $this->rowAt($this->branch, 'B-20002');
        $this->actingAs($this->branchOwner, 'web')->delete(route('app.products.destroy', $b))->assertSessionHasNoErrors();
        $this->assertNull($this->rowAt($this->branch, 'B-20002'), 'gone at the branch');
        $this->assertNull($this->rowAt($this->main, 'B-20002'), 'gone at the main shop too');
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->whereIn('product_id', Product::withoutGlobalScopes()->where('name', 'B-20002')->pluck('id'))->whereNull('deleted_at')->count(), 'no live variant left anywhere');
        $this->assertStockValueEqual('after a product delete');
        $this->assertBarcodesUniquePerShop();

        // restore it (master side) -> comes back in BOTH shops with its variants and stock
        $trashed = Product::withoutGlobalScopes()->onlyTrashed()->where('name', 'B-20002')->get();
        $this->assertCount(2, $trashed, 'both copies sit in the bin');
        Tenancy::clear();
        $trashed->firstWhere('shop_id', $this->main->id)->restore();
        $this->assertPoolHealthy('B-20002', 'after the restore');
        $this->assertEquals(2, (float) $this->rowAt($this->branch, 'B-20002')->stock);

        // delete at MAIN -> branch follows
        $m = $this->rowAt($this->main, 'B-20003');
        $this->actingAs($this->mainOwner, 'web')->delete(route('app.products.destroy', $m))->assertSessionHasNoErrors();
        $this->assertNull($this->rowAt($this->branch, 'B-20003'));
        $this->assertStockValueEqual('after the main-side delete');
    }

    public function test_deleting_and_restoring_a_single_variant_cascades_and_keeps_the_totals_straight(): void
    {
        $mv = $this->variantAt($this->main, 'B-20004', 'RED', '36');
        // give the article a second size so the product survives the variant delete
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.store', $this->rowAt($this->main, 'B-20004')), ['size' => '37', 'color' => 'RED', 'stock' => 5, 'barcode' => 'B-20004-RED-37'])->assertSessionHasNoErrors();
        $this->assertPoolHealthy('B-20004', 'after adding a second size');
        $this->assertEquals(7, (float) $this->rowAt($this->branch, 'B-20004')->stock);

        // delete the 36 from the BRANCH side
        $bv = $this->variantAt($this->branch, 'B-20004', 'RED', '36');
        $this->actingAs($this->branchOwner, 'web')->delete(route('app.productVariants.destroy', $bv))->assertSessionHasNoErrors();
        $this->assertNull($this->variantAt($this->main, 'B-20004', 'RED', '36'), 'sibling variant deleted at main');
        $this->assertNull($this->variantAt($this->branch, 'B-20004', 'RED', '36'));
        [$m, $b] = $this->assertPoolHealthy('B-20004', 'after a single-variant delete');
        $this->assertEquals(5, (float) $m->stock, 'only the 37 (5 pcs) is left in the product total');
        $this->assertEquals(5, (float) $b->stock);

        // the freed barcode is reusable at once, in both shops
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.store', $this->rowAt($this->main, 'B-20004')), ['size' => '38', 'color' => 'RED', 'stock' => 1, 'barcode' => 'B-20004-RED-36'])->assertSessionHasNoErrors();
        $this->assertPoolHealthy('B-20004', 'after reusing the freed barcode');

        // delete the 37 at MAIN, then bring it back (restore) -> back in both shops with its stock
        $v37 = $this->variantAt($this->main, 'B-20004', 'RED', '37');
        $this->actingAs($this->mainOwner, 'web')->delete(route('app.productVariants.destroy', $v37))->assertSessionHasNoErrors();
        $this->assertNull($this->variantAt($this->branch, 'B-20004', 'RED', '37'), 'branch twin deleted too');
        $this->assertPoolHealthy('B-20004', 'after deleting the 37');
        $this->assertEquals(1, (float) $this->rowAt($this->branch, 'B-20004')->stock, 'only the 38 (1 pc) remains');

        // there is no "restore one size" screen — the owner brings a size back by adding it again, and it
        // must come back in BOTH shops exactly once (no phantom stock, no duplicate row)
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.store', $this->rowAt($this->main, 'B-20004')), ['size' => '37', 'color' => 'RED', 'stock' => 4, 'barcode' => 'B-20004-RED-37'])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20004', 'after re-adding the deleted size');
        $this->assertEquals(4, (float) $this->variantAt($this->branch, 'B-20004', 'RED', '37')->stock);
        $this->assertEquals(5, (float) $m->stock, 'the 38 (1) + the re-added 37 (4)');
        $this->assertEquals(5, (float) $b->stock);
        $this->assertNotNull($mv);
    }

    // ============================================================ 3. STOCK IN / DECREASE / SET EXACT / DAMAGE

    public function test_variant_stock_in_from_a_supplier_on_credit_moves_both_shops_the_ledger_and_the_payable(): void
    {
        $mv = $this->variantAt($this->main, 'B-20001', 'RED', '36');
        $payable0 = $this->payable();

        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.stockIn', $mv), [
            'qty' => 4, 'cost' => 150, 'supplier_id' => $this->supplier->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        [$m, $b] = $this->assertPoolHealthy('B-20001', 'after a variant stock-in');
        $this->assertEquals(6, (float) $m->stock);
        $this->assertEquals(6, (float) $this->variantAt($this->branch, 'B-20001', 'RED', '36')->stock, 'the branch variant moved too');
        $this->assertEquals($payable0 + 600, $this->payable(), 'credit stock-in adds qty x cost to the supplier payable');
        $intake = StockIntake::withoutGlobalScopes()->where('product_variant_id', $mv->id)->where('source', 'variant_stock_in')->first();
        $this->assertNotNull($intake, 'intake ledger row exists');
        $this->assertEquals(4, (float) $intake->qty);
        $this->assertEquals(600, (float) $intake->total_cost);
        $this->assertSame($this->supplier->id, (int) $intake->supplier_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.variant.stockIn']);

        // a stock-in typed at the BRANCH lands in the pool just the same (cash, no supplier -> no payable change)
        $bv = $this->variantAt($this->branch, 'B-20001', 'RED', '36');
        $this->actingAs($this->branchOwner, 'web')->post(route('app.productVariants.stockIn', $bv), ['qty' => 2, 'cost' => 150])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20001', 'after a branch-side stock-in');
        $this->assertEquals(8, (float) $m->stock);
        $this->assertEquals($payable0 + 600, $this->payable(), 'no supplier, no payable');
    }

    public function test_plain_product_stock_in_with_a_supplier_on_credit(): void
    {
        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.store'), ['name' => 'Insole', 'cost' => 30, 'price' => 50, 'stock' => 2])->assertSessionHasNoErrors();
        $m = $this->rowAt($this->main, 'Insole');
        $payable0 = $this->payable();

        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.stockIn', $m), [
            'qty' => 10, 'cost' => 35, 'supplier_id' => $this->supplier->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        [$m, $b] = $this->assertPoolHealthy('Insole', 'after a plain stock-in');
        $this->assertEquals(12, (float) $m->stock);
        $this->assertEquals(12, (float) $b->stock);
        $this->assertEquals($payable0 + 350, $this->payable());
        $this->assertEquals(350, (float) StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->where('source', 'product_stock_in')->sum('total_cost'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.stockIn']);

        // a cash payment settles from the shop's own cash, not the payable
        $cash0 = (float) Shop::withoutGlobalScopes()->find($this->main->id)->cash_balance;
        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.stockIn', $m), ['qty' => 1, 'cost' => 40, 'supplier_id' => $this->supplier->id, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals($cash0 - 40, (float) Shop::withoutGlobalScopes()->find($this->main->id)->cash_balance);
        $this->assertEquals($payable0 + 350, $this->payable());
        $this->assertPoolHealthy('Insole', 'after the cash stock-in');
    }

    public function test_decrease_set_exact_and_damage_keep_every_layer_in_step(): void
    {
        $mv = $this->variantAt($this->main, 'B-20006', 'RED', '36');
        $this->assertEquals(2, (float) $mv->stock);

        // top up to 10 first so there is room to work with
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.setExactStock', $mv), ['stock' => 10])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20006', 'after set-exact up');
        $this->assertEquals(10, (float) $m->stock);
        $this->assertEquals(10, (float) $b->stock);

        // decrease (wrong entry) from the BRANCH
        $bv = $this->variantAt($this->branch, 'B-20006', 'RED', '36');
        $this->actingAs($this->branchOwner, 'web')->post(route('app.productVariants.decreaseStock', $bv), ['qty' => 3, 'reason' => 'wrong_entry'])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20006', 'after a decrease');
        $this->assertEquals(7, (float) $m->stock);
        $this->assertEquals(7, (float) $this->variantAt($this->main, 'B-20006', 'RED', '36')->stock);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.variant.stockDecrease']);

        // decrease more than exists is refused and writes nothing
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.decreaseStock', $mv), ['qty' => 99, 'reason' => 'lost'])->assertSessionHasErrors('qty');
        $this->assertPoolHealthy('B-20006', 'after the refused decrease');
        $this->assertEquals(7, (float) $this->rowAt($this->main, 'B-20006')->stock);

        // a "damaged" decrease also writes a Damage row (same record every write-off path writes)
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.decreaseStock', $mv), ['qty' => 1, 'reason' => 'damaged'])->assertSessionHasNoErrors();
        $this->assertSame(1, Damage::withoutGlobalScopes()->where('product_variant_id', $mv->id)->count());
        $this->assertPoolHealthy('B-20006', 'after the damaged decrease');
        $this->assertEquals(6, (float) $this->rowAt($this->branch, 'B-20006')->stock);

        // set-exact DOWN (recount)
        $this->actingAs($this->mainOwner, 'web')->post(route('app.productVariants.setExactStock', $mv), ['stock' => 4])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20006', 'after set-exact down');
        $this->assertEquals(4, (float) $m->stock);
        $this->assertEquals(4, (float) $b->stock);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.variant.setExactStock']);

        // dedicated Damage form on a variant, then on a plain product
        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), ['product_id' => $m->id, 'product_variant_id' => $mv->id, 'qty' => 2, 'reason' => 'chewed'])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('B-20006', 'after a Damage entry');
        $this->assertEquals(2, (float) $m->stock);
        $this->assertEquals(2, (float) $b->stock);
        $this->assertEquals(2 * 100, (float) Damage::withoutGlobalScopes()->where('product_variant_id', $mv->id)->where('reason', 'chewed')->value('loss'), 'loss = qty x cost');

        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.store'), ['name' => 'Wax', 'cost' => 20, 'price' => 30, 'stock' => 6])->assertSessionHasNoErrors();
        $wax = $this->rowAt($this->main, 'Wax');
        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), ['product_id' => $wax->id, 'qty' => 2, 'reason' => 'spilled'])->assertSessionHasNoErrors();
        [$m, $b] = $this->assertPoolHealthy('Wax', 'after a plain-product Damage');
        $this->assertEquals(4, (float) $m->stock);
        $this->assertEquals(4, (float) $b->stock);

        // a Damage larger than what exists is refused, nothing moves
        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), ['product_id' => $wax->id, 'qty' => 50])->assertSessionHasErrors('qty');
        $this->assertPoolHealthy('Wax', 'after the refused Damage');
        $this->assertEquals(4, (float) $this->rowAt($this->branch, 'Wax')->stock);
    }

    // ============================================================ 4. CSV IMPORT + OPENING-STOCK GRID

    public function test_a_csv_import_at_the_branch_lands_in_both_shops_and_a_re_import_does_not_double_the_stock(): void
    {
        $csv = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\n"
            ."IMP-1,IMP-1,IMP-1-RED-36,BABY,,100,200,,4,,36,RED\n"
            ."IMP-1,IMP-1,IMP-1-RED-37,BABY,,100,200,,6,,37,RED\n"
            ."Plain Import,Plain Import,PLAINIMP-1,,,10,20,,7,,,\n";

        $post = fn () => $this->actingAs($this->branchOwner, 'web')
            ->post(route('app.products.import.store'), ['file' => UploadedFile::fake()->createWithContent('import.csv', $csv)])
            ->assertSessionHasNoErrors();
        $post();

        [$m, $b] = $this->assertPoolHealthy('IMP-1', 'after the CSV import');
        $this->assertEquals(10, (float) $m->stock);
        $this->assertEquals(10, (float) $b->stock);
        $this->assertNotNull($this->variantAt($this->main, 'IMP-1', 'RED', '37'), 'the second size reached the main shop');
        [$pm, $pb] = $this->assertPoolHealthy('Plain Import', 'plain import row');
        $this->assertEquals(7, (float) $pm->stock);
        $this->assertEquals(7, (float) $pb->stock);

        // Tenancy of the pool: ZV stickers of the imported variants resolve at both shops
        foreach (ProductVariant::withoutGlobalScopes()->whereIn('product_id', [$m->id, $b->id])->get() as $v) {
            Tenancy::set($this->main->id);
            $this->assertTrue(BarcodeResolver::resolve('ZV'.$v->id)['found'], 'imported variant sticker resolves at main');
            Tenancy::set($this->branch->id);
            $this->assertTrue(BarcodeResolver::resolve('ZV'.$v->id)['found'], 'imported variant sticker resolves at branch');
        }

        // importing the same file again must not create duplicates or double-mirror
        $post();
        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $this->main->id)->where('name', 'IMP-1')->whereNull('deleted_at')->count(), 'no duplicate article at main');
        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $this->branch->id)->where('name', 'IMP-1')->whereNull('deleted_at')->count(), 'no duplicate article at the branch');
        $this->assertPoolHealthy('IMP-1', 'after the repeat import');
        $this->assertSame(
            (float) $this->rowAt($this->main, 'IMP-1')->stock,
            (float) ProductVariant::withoutGlobalScopes()->where('product_id', $this->rowAt($this->main, 'IMP-1')->id)->whereNull('deleted_at')->sum('stock'),
            'products.stock still equals the sum of its variants after a repeat import'
        );
    }

    public function test_the_opening_stock_grid_adds_a_new_colour_size_cell_and_fills_existing_ones_everywhere(): void
    {
        $m = $this->rowAt($this->main, 'B-20007');
        $existing = $this->variantAt($this->main, 'B-20007', 'RED', '36');
        $payable0 = $this->payable();

        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.openingStock', $m), [
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'credit',
            'default_cost' => 90,
            'cells' => [
                ['variant_id' => $existing->id, 'qty' => 3],
                ['color' => 'GREEN', 'size' => '38', 'qty' => 4, 'cost' => 95],
            ],
        ])->assertSessionHasNoErrors();

        [$mm, $bb] = $this->assertPoolHealthy('B-20007', 'after the opening-stock grid');
        $this->assertEquals(2 + 3 + 4, (float) $mm->stock);
        $this->assertEquals(9, (float) $bb->stock);
        $newMain = $this->variantAt($this->main, 'B-20007', 'GREEN', '38');
        $newBranch = $this->variantAt($this->branch, 'B-20007', 'GREEN', '38');
        $this->assertNotNull($newMain, 'new cell exists at main');
        $this->assertNotNull($newBranch, 'new cell mirrored to the branch');
        $this->assertEquals(4, (float) $newMain->stock);
        $this->assertEquals(4, (float) $newBranch->stock);
        $this->assertSame('ZV'.$newMain->id, $newMain->barcode, 'a printable ZV barcode at once');
        $this->assertEquals(5 * 0 + 3 * 90 + 4 * 95, (float) StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->where('source', 'opening_stock')->sum('total_cost'), 'two ledger rows at the paid costs');
        $this->assertSame(2, StockIntake::withoutGlobalScopes()->where('product_id', $m->id)->where('source', 'opening_stock')->count());
        $this->assertEquals($payable0 + 650, $this->payable(), 'credit opening stock adds to the supplier payable');

        // the same colour+size typed again (different case) is refused and writes nothing
        $this->actingAs($this->mainOwner, 'web')->post(route('app.products.openingStock', $m), ['cells' => [['color' => 'green', 'size' => '38', 'qty' => 1]]])->assertStatus(422);
        $this->assertPoolHealthy('B-20007', 'after the refused duplicate cell');
        $this->assertEquals(9, (float) $this->rowAt($this->main, 'B-20007')->stock);
    }

    // ============================================================ 5. STOCK TRANSFER

    public function test_a_transfer_between_the_two_branches_leaves_the_pooled_net_unchanged(): void
    {
        $mv = $this->variantAt($this->main, 'B-20008', 'RED', '36');
        $valueBefore = $this->stockValue($this->main);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.stockTransfers.store'), [
            'to_shop_id' => $this->branch->id,
            'product_id' => $this->rowAt($this->main, 'B-20008')->id,
            'lines' => [['product_variant_id' => $mv->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();

        [$m, $b] = $this->assertPoolHealthy('B-20008', 'after a transfer');
        $this->assertEquals(2, (float) $m->stock, 'the pool total is unchanged by a transfer');
        $this->assertEquals(2, (float) $b->stock);
        $this->assertEquals(2, (float) $this->variantAt($this->branch, 'B-20008', 'RED', '36')->stock);
        $this->assertEqualsWithDelta($valueBefore, $this->stockValue($this->branch), 0.01, 'stock value did not move');
        $this->assertDatabaseHas('stock_transfers', ['from_shop_id' => $this->main->id, 'to_shop_id' => $this->branch->id, 'product_name' => 'B-20008', 'qty' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stockTransfer.create']);

        // more than the pool holds is refused and nothing moves
        $this->actingAs($this->mainOwner, 'web')->post(route('app.stockTransfers.store'), [
            'to_shop_id' => $this->branch->id,
            'product_id' => $this->rowAt($this->main, 'B-20008')->id,
            'lines' => [['product_variant_id' => $mv->id, 'qty' => 50]],
        ])->assertSessionHasErrors('lines');
        $this->assertPoolHealthy('B-20008', 'after the refused transfer');
        $this->assertEquals(2, (float) $this->rowAt($this->branch, 'B-20008')->stock);
    }

    public function test_a_transfer_that_needs_confirmation_holds_the_stock_in_transit_then_accept_puts_it_back(): void
    {
        $this->grantFeature($this->main, 'transfer_confirmation');
        $mv = $this->variantAt($this->main, 'B-20009', 'RED', '36');

        $this->actingAs($this->mainOwner, 'web')->post(route('app.stockTransfers.store'), [
            'to_shop_id' => $this->branch->id,
            'product_id' => $this->rowAt($this->main, 'B-20009')->id,
            'lines' => [['product_variant_id' => $mv->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();
        $transfer = \App\Models\StockTransfer::withoutGlobalScopes()->where('product_name', 'B-20009')->firstOrFail();
        $this->assertSame('pending', $transfer->status);
        $this->assertPoolHealthy('B-20009', 'while in transit');
        $this->assertEquals(1, (float) $this->rowAt($this->branch, 'B-20009')->stock, 'one piece is on the road, in nobody\'s count');

        $this->actingAs($this->branchOwner, 'web')->post(route('app.stockTransfers.accept', $transfer->id))->assertSessionHasNoErrors();
        $this->assertSame('completed', $transfer->fresh()->status);
        [$m, $b] = $this->assertPoolHealthy('B-20009', 'after accept');
        $this->assertEquals(2, (float) $m->stock, 'accepted -> the pool is whole again');
        $this->assertEquals(2, (float) $b->stock);
    }
}
