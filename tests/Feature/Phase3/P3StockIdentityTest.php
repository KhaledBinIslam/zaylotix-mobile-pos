<?php

namespace Tests\Feature\Phase3;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — C. Stock movement and reconciliation.
 *
 * The stock figure is compared, after EVERY step, with an independent model that knows only the documented rules:
 *   sale -q · void +(q - returned) · return resalable +q (damaged +0) · purchase +q · supplier return -q · damage -q
 *   count = absolute · stock of a variant product == sum of its sizes.
 * Fixed seeds, so a failure is reproducible.
 */
class P3StockIdentityTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    /** @var array<int,float> variant id => modelled stock */
    private array $mv = [];

    /** @var array<int,float> plain product id => modelled stock */
    private array $mp = [];

    /** @var array<int,array{item:int,pid:int,vid:?int,qty:int,returned:int,voided:bool,sale:int}> */
    private array $sales = [];

    private Product $shoe;

    private Product $cap;

    /** @var array<int,ProductVariant> */
    private array $sizes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot();
        $this->shoe = $this->p3Product('Shoe', 60, 100, 0);
        foreach (['S', 'M', 'L'] as $size) {
            $v = $this->p3Variant($this->shoe, $size, 20, ['price' => 100, 'cost' => 60]);
            $this->sizes[$v->id] = $v;
            $this->mv[$v->id] = 20.0;
        }
        $this->cap = $this->p3Product('Cap', 30, 100, 60);
        $this->mp[$this->cap->id] = 60.0;
    }

    private function assertModelMatches(string $after): void
    {
        foreach ($this->mv as $vid => $expected) {
            $actual = (float) DB::table('product_variants')->where('id', $vid)->value('stock');
            $this->assertEquals($expected, $actual, "size #{$vid}: model {$expected}, database {$actual} after {$after}");
        }
        $shoeTotal = (float) DB::table('products')->where('id', $this->shoe->id)->value('stock');
        $this->assertEquals(array_sum($this->mv), $shoeTotal, "the shoe's own stock must equal the sum of its sizes after {$after}");
        foreach ($this->mp as $pid => $expected) {
            $actual = (float) DB::table('products')->where('id', $pid)->value('stock');
            $this->assertEquals($expected, $actual, "product #{$pid}: model {$expected}, database {$actual} after {$after}");
        }
    }

    /** @return array<string,array{0:int}> */
    public static function seeds(): array
    {
        return ['seed 1' => [1], 'seed 2' => [2], 'seed 3' => [3]];
    }

    #[DataProvider('seeds')]
    public function test_stock_matches_an_independent_model_after_every_step_of_a_random_day(int $seed): void
    {
        mt_srand($seed);
        $steps = 45;
        $executed = [];
        for ($i = 1; $i <= $steps; $i++) {
            $op = ['sell', 'sell', 'sell', 'void', 'return', 'return', 'purchase', 'supplier_return', 'damage', 'count'][mt_rand(0, 9)];
            $label = "step {$i} ({$op}, seed {$seed})";
            $done = match ($op) {
                'sell' => $this->doSell(),
                'void' => $this->doVoid(),
                'return' => $this->doReturn(),
                'purchase' => $this->doPurchase(),
                'supplier_return' => $this->doSupplierReturn(),
                'damage' => $this->doDamage(),
                'count' => $this->doCount(),
            };
            if ($done) {
                $executed[$op] = ($executed[$op] ?? 0) + 1;
                $this->assertModelMatches($label);
            }
        }
        // guard against a vacuous run: the day must really have contained several kinds of movement
        $this->assertGreaterThanOrEqual(15, array_sum($executed), 'too few operations were actually applied: '.json_encode($executed));
        $this->assertGreaterThanOrEqual(5, $executed['sell'] ?? 0, 'the day contained sales: '.json_encode($executed));
        $this->assertGreaterThanOrEqual(4, count($executed), 'at least four kinds of movement happened: '.json_encode($executed));
    }

    // ---- operations: each applies the documented rule to the model only if the app accepted it

    private function target(): array
    {
        if (mt_rand(0, 2) === 0) {
            return [$this->cap->id, null];
        }

        return [$this->shoe->id, array_rand($this->sizes)];
    }

    private function modelStock(int $pid, ?int $vid): float
    {
        return $vid ? $this->mv[$vid] : $this->mp[$pid];
    }

    private function bump(int $pid, ?int $vid, float $by): void
    {
        $vid ? $this->mv[$vid] += $by : $this->mp[$pid] += $by;
    }

    private function doSell(): bool
    {
        [$pid, $vid] = $this->target();
        $avail = (int) $this->modelStock($pid, $vid);
        if ($avail < 1) {
            return false;
        }
        $qty = mt_rand(1, min(3, $avail));
        $item = ['product_id' => $pid, 'qty' => $qty] + ($vid ? ['product_variant_id' => $vid] : []);
        $sale = $this->p3Sell([$item], [['method' => 'cash', 'amount' => 100 * $qty]]);
        $this->bump($pid, $vid, -$qty);
        $this->sales[$sale->id] = ['item' => $this->p3ItemId($sale), 'pid' => $pid, 'vid' => $vid, 'qty' => $qty, 'returned' => 0, 'voided' => false, 'sale' => $sale->id];

        return true;
    }

    private function doVoid(): bool
    {
        $open = array_filter($this->sales, fn ($s) => ! $s['voided']);
        if (! $open) {
            return false;
        }
        $s = $open[array_rand($open)];
        $r = $this->p3Void(\App\Models\Sale::withoutGlobalScopes()->find($s['sale']));
        $this->assertTrue($r->getSession()->has('success'), 'void was refused: '.json_encode($r->getSession()->get('error')));
        $this->bump($s['pid'], $s['vid'], $s['qty'] - $s['returned']);
        $this->sales[$s['sale']]['voided'] = true;

        return true;
    }

    private function doReturn(): bool
    {
        $open = array_filter($this->sales, fn ($s) => ! $s['voided'] && $s['qty'] - $s['returned'] > 0);
        if (! $open) {
            return false;
        }
        $s = $open[array_rand($open)];
        $qty = mt_rand(1, $s['qty'] - $s['returned']);
        $condition = mt_rand(0, 4) === 0 ? 'damaged' : 'resalable';
        $r = $this->p3Return(\App\Models\Sale::withoutGlobalScopes()->find($s['sale']), [['sale_item_id' => $s['item'], 'qty' => $qty, 'condition' => $condition]]);
        $this->assertSame(200, $r->status(), 'return refused: '.substr((string) $r->getContent(), 0, 200));
        if ($condition === 'resalable') {
            $this->bump($s['pid'], $s['vid'], $qty);
        }
        $this->sales[$s['sale']]['returned'] += $qty;

        return true;
    }

    private function doPurchase(): bool
    {
        [$pid, $vid] = $this->target();
        $qty = mt_rand(1, 10);
        $r = $this->p3As()->post('/app/purchases', ['amount' => 50 * $qty, 'method' => 'cash', 'status' => 'received', 'product_id' => $pid, 'qty' => $qty, 'cost' => 50] + ($vid ? ['product_variant_id' => $vid] : []));
        $this->assertFalse($r->getSession()->has('errors'), 'purchase refused: '.json_encode($r->getSession()->get('errors')?->all()));
        $this->bump($pid, $vid, $qty);

        return true;
    }

    private function doSupplierReturn(): bool
    {
        [$pid, $vid] = $this->target();
        $avail = (int) $this->modelStock($pid, $vid);
        if ($avail < 1) {
            return false;
        }
        $qty = mt_rand(1, min(4, $avail));
        $r = $this->p3As()->postJson('/app/supplier-returns', ['settlement_method' => 'cash', 'reason' => 'QA', 'lines' => [['product_id' => $pid, 'qty' => $qty, 'amount' => 50 * $qty] + ($vid ? ['product_variant_id' => $vid] : [])]]);
        $this->assertFalse($r->getSession()->has('errors'), 'supplier return refused: '.json_encode($r->getSession()->get('errors')?->all()));
        $this->bump($pid, $vid, -$qty);

        return true;
    }

    private function doDamage(): bool
    {
        [$pid, $vid] = $this->target();
        $avail = (int) $this->modelStock($pid, $vid);
        if ($avail < 1) {
            return false;
        }
        $qty = mt_rand(1, min(3, $avail));
        $r = $this->p3As()->post('/app/damages', ['product_id' => $pid, 'qty' => $qty, 'reason' => 'QA'] + ($vid ? ['product_variant_id' => $vid] : []));
        $this->assertFalse($r->getSession()->has('errors'), 'damage refused: '.json_encode($r->getSession()->get('errors')?->all()));
        $this->bump($pid, $vid, -$qty);

        return true;
    }

    private function doCount(): bool
    {
        [$pid, $vid] = $this->target();
        $to = mt_rand(0, 30);
        $r = $this->p3As()->post('/app/stock-count', ['counts' => [['product_id' => $pid, 'counted' => $to] + ($vid ? ['product_variant_id' => $vid] : [])]]);
        $this->assertFalse($r->getSession()->has('errors'), 'count refused: '.json_encode($r->getSession()->get('errors')?->all()));
        if ($vid) {
            $this->mv[$vid] = (float) $to;
        } else {
            $this->mp[$pid] = (float) $to;
        }

        return true;
    }

    // ---- deterministic boundaries

    public function test_damage_beyond_the_stock_on_hand_is_refused_and_changes_nothing(): void
    {
        $vid = array_key_first($this->sizes);
        $start = $this->p3Snap();

        $r = $this->p3As()->post('/app/damages', ['product_id' => $this->shoe->id, 'product_variant_id' => $vid, 'qty' => 21, 'reason' => 'QA']);

        $this->assertTrue($r->status() >= 400 || $r->getSession()->has('errors'), 'damaging more than exists must be refused');
        $this->p3AssertSame($start, $this->p3Snap());
        $this->assertSame(0, DB::table('damages')->count());
    }

    public function test_a_stock_count_to_the_same_figure_changes_nothing_and_a_new_figure_is_absolute(): void
    {
        $vid = array_key_first($this->sizes);
        $start = $this->p3Snap();

        $this->p3As()->post('/app/stock-count', ['counts' => [['product_id' => $this->shoe->id, 'product_variant_id' => $vid, 'counted' => 20]]]);
        $this->p3AssertSame($start, $this->p3Snap(), 'counting what is already there');

        $this->p3As()->post('/app/stock-count', ['counts' => [['product_id' => $this->shoe->id, 'product_variant_id' => $vid, 'counted' => 7]]]);
        $this->assertEquals(7.0, (float) DB::table('product_variants')->where('id', $vid)->value('stock'));
        $this->assertEquals(47.0, (float) DB::table('products')->where('id', $this->shoe->id)->value('stock'), '7 + 20 + 20: the shoe follows its sizes');
    }

    public function test_a_transfer_between_two_branches_conserves_the_stock_and_a_refused_one_moves_nothing(): void
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $this->shop->id]);
        $vid = array_key_first($this->sizes);
        $total = fn () => (float) DB::table('product_variants')->whereIn('shop_id', [$this->shop->id, $branch->id])->whereNull('deleted_at')->sum('stock');
        $before = $total();

        $this->p3As()->post('/app/stock-transfers', ['to_shop_id' => $branch->id, 'product_id' => $this->shoe->id, 'lines' => [['product_variant_id' => $vid, 'qty' => 5]]])->assertSessionHasNoErrors();

        $this->assertEquals($before, $total(), 'what left one shop arrived at the other — nothing created or lost');
        $this->assertEquals(15.0, (float) DB::table('product_variants')->where('id', $vid)->value('stock'));

        $afterOk = $total();
        $r = $this->p3As()->post('/app/stock-transfers', ['to_shop_id' => $branch->id, 'product_id' => $this->shoe->id, 'lines' => [['product_variant_id' => $vid, 'qty' => 999]]]);
        $this->assertTrue($r->status() >= 400 || $r->getSession()->has('errors'), 'more than exists must be refused');
        $this->assertEquals($afterOk, $total(), 'a refused transfer moved nothing');
    }

    public function test_a_transfer_to_a_shop_outside_the_business_is_refused(): void
    {
        [$stranger] = $this->createShopWithOwner();
        $vid = array_key_first($this->sizes);
        $start = $this->p3Snap();

        $r = $this->p3As()->post('/app/stock-transfers', ['to_shop_id' => $stranger->id, 'product_id' => $this->shoe->id, 'lines' => [['product_variant_id' => $vid, 'qty' => 1]]]);

        $this->assertContains($r->status(), [403, 404, 422]);
        $this->p3AssertSame($start, $this->p3Snap());
        $this->assertSame(0, (int) DB::table('product_variants')->where('shop_id', $stranger->id)->count(), 'nothing was created in the other business');
    }
}
