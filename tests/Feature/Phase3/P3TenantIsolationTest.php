<?php

namespace Tests\Feature\Phase3;

use App\Models\Feature;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — F. Tenant (and branch) isolation.
 *
 * 1. Static audit: every model whose table carries `shop_id` must wear the tenant scope, or be on a short, argued allowlist.
 * 2. Generated IDOR probe: a logged-in owner of shop A (EVERY feature switched on, so no route is hidden behind a
 *    feature gate) calls every `/app/*` route that binds an Eloquent model, with the id of a real row of shop B, using
 *    every HTTP verb the route answers. The only acceptable answers are 403/404; anything else means the victim's
 *    row was resolved for a stranger. Afterwards a checksum of shop B's tables proves nothing changed.
 * The routes come from the router at run time, so a route added tomorrow is probed tomorrow without touching this file.
 */
class P3TenantIsolationTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    /** tables with a shop_id that are deliberately NOT globally scoped, and why */
    private const UNSCOPED_ON_PURPOSE = [
        'Shop' => 'the tenant itself',
        'Customer' => 'shared by every branch of ONE business on purpose (BusinessScope, see the model docblock); the probe below proves a stranger business still gets 404',
        'User' => 'a login belongs to a shop but is looked up by phone before any tenant exists; staff routes are probed below',
        'AmbassadorCommission' => 'admin-side commission ledger, no shop-user route',
        'AmbassadorShopAssignment' => 'admin-side, no shop-user route',
        'SubscriptionPayment' => 'admin-side billing record, no shop-user route',
    ];

    private Shop $attackerShop;

    private User $attacker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        // the VICTIM is the fixture shop (shop B)
        $this->p3Boot(['shared_catalog', 'expenses']);
        [$this->attackerShop, $this->attacker] = $this->createShopWithOwner(['cash_balance' => 5000]);
        $this->attackerShop->features()->syncWithoutDetaching(Feature::where('key', '!=', 'restaurant_tables')->pluck('id')->all());
    }

    // ---------------------------------------------------------------- 1. static audit

    public function test_every_model_with_a_shop_id_column_wears_the_tenant_scope_or_is_on_the_argued_allowlist(): void
    {
        $unscoped = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }
            $table = (new $class)->getTable();
            if (! Schema::hasColumn($table, 'shop_id')) {
                continue;
            }
            $traits = [];
            for ($c = $ref; $c; $c = $c->getParentClass()) {
                $traits = array_merge($traits, array_keys($c->getTraits()));
            }
            if (! in_array('App\\Models\\Concerns\\BelongsToTenant', $traits, true) && ! array_key_exists($ref->getShortName(), self::UNSCOPED_ON_PURPOSE)) {
                $unscoped[] = $ref->getShortName()." ({$table})";
            }
        }

        $this->assertSame([], $unscoped, 'models with shop_id and no tenant scope, not on the allowlist: '.implode(', ', $unscoped));
    }

    // ---------------------------------------------------------------- 2. generated probe

    /** Everything a shop owner can create, in shop B, so most route bindings have a real victim row to aim at. */
    private function seedVictim(): void
    {
        $p = $this->p3Product('Victim Shirt', 40, 100, 0);
        $v = $this->p3Variant($p, 'M', 10, ['price' => 100, 'cost' => 40]);
        $plain = $this->p3Product('Victim Plain', 20, 50, 30);
        $cust = $this->p3Customer('01788888888', 0);
        $sup = $this->p3Supplier('Victim Supplier', 0);

        $sale = $this->p3Sell([['product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
        $this->p3Sell([['product_id' => $plain->id, 'qty' => 3]], [['method' => 'cash', 'amount' => 50]], ['customer_id' => $cust->id]);
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']]);
        $this->p3As()->post('/app/purchases', ['amount' => 400, 'method' => 'credit', 'status' => 'received', 'supplier_id' => $sup->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 10, 'cost' => 40]);
        $this->p3As()->post('/app/purchases', ['amount' => 90, 'method' => 'cash', 'status' => 'pending', 'supplier_id' => $sup->id, 'product_id' => $plain->id, 'qty' => 3, 'cost' => 30]);
        $this->p3As()->post('/app/damages', ['product_id' => $plain->id, 'qty' => 1, 'reason' => 'QA']);
        $this->p3As()->post('/app/expenses', ['amount' => 30, 'method' => 'cash', 'category' => 'rent', 'note' => 'QA']);
        $this->p3As()->post('/app/staff', ['name' => 'Victim Cashier', 'phone' => '01799999999', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'cashier']);
    }

    /** route parameter name -> model class, by convention: {sale} -> Sale, {po} -> PurchaseOrder, .../service-ticket-items/{item} -> ServiceTicketItem */
    private function guessModel(string $name, array $segments): ?string
    {
        $special = ['po' => 'PurchaseOrder', 'plan' => 'InstallmentPlan', 'channel' => 'SalesChannel', 'ticket' => 'ServiceTicket', 'onlineOrder' => 'Sale'];
        $candidates = [];
        if (isset($special[$name])) {
            $candidates[] = $special[$name];
        }
        $candidates[] = \Illuminate\Support\Str::studly($name);
        foreach ($segments as $i => $seg) {
            if ($seg === '{'.$name.'}' && $i > 0) {
                $candidates[] = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($segments[$i - 1]));
            }
        }
        foreach ($candidates as $c) {
            $class = 'App\\Models\\'.$c;
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $class;
            }
        }

        return null;
    }

    /** any real row of the model (shop B is the only shop that owns data); one is fabricated when B has none */
    private function victimRow(string $class): ?Model
    {
        $row = $class::withoutGlobalScopes()->orderBy('id')->first();

        return $row ?: $this->fabricate($class);
    }

    private function fabricate(string $class): ?Model
    {
        try {
            $table = (new $class)->getTable();
            if (! Schema::hasTable($table)) {
                return null;
            }
            $sql = (string) DB::table('sqlite_master')->where('name', $table)->value('sql');
            $row = [];
            foreach (Schema::getColumns($table) as $c) {
                $n = $c['name'];
                if ($n === 'id' || ! empty($c['auto_increment']) || $c['default'] !== null) {
                    continue;
                }
                if ($n === 'shop_id') {
                    $row[$n] = $this->shop->id;

                    continue;
                }
                if ($c['nullable']) {
                    continue;
                }
                if (preg_match('/"'.preg_quote($n, '/').'"[^,]*?check \("'.preg_quote($n, '/').'" in \(\'([^\']*)\'/i', $sql, $m)) {
                    $row[$n] = $m[1];

                    continue;
                }
                $type = strtolower($c['type_name'] ?? $c['type'] ?? '');
                $row[$n] = match (true) {
                    str_contains($type, 'int') => 1,
                    str_contains($type, 'bool') => 0,
                    str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double'), str_contains($type, 'numeric'), str_contains($type, 'real') => 0,
                    $type === 'date' => now()->toDateString(),
                    str_contains($type, 'time') => now()->toDateTimeString(),
                    str_contains($type, 'json') => '[]',
                    default => 'x'.random_int(1000, 9999),
                };
            }
            $id = DB::table($table)->insertGetId($row);

            return $class::withoutGlobalScopes()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /** a fingerprint of everything shop B owns */
    private function victimChecksum(): array
    {
        $tables = ['shops', 'products', 'product_variants', 'customers', 'suppliers', 'sales', 'sale_items', 'sale_payments', 'payments', 'purchases', 'expenses', 'damages', 'returns', 'supplier_returns', 'users', 'product_sources', 'stock_intakes'];
        $out = [];
        foreach ($tables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            $q = DB::table($t);
            if ($t === 'shops') {
                $q->where('id', $this->shop->id);
            } elseif (Schema::hasColumn($t, 'shop_id')) {
                $q->where('shop_id', $this->shop->id);
            }
            $out[$t] = md5(json_encode($q->orderBy('id')->get()->all()));
        }

        return $out;
    }

    /** @return array{requests:int,leaks:array,inconclusive:array,skipped:array,unmapped:array,probed:array,statuses:array,before:array,after:array} */
    private function probe(User $actor, bool $getOnly): array
    {
        $statuses = [];
        $plan = [];       // [method, url, models aimed at] — built first, fired second
        $probed = [];     // class => number of requests
        $skipped = [];    // routes with a bound model but no victim row to aim at
        $unmapped = [];   // routes whose parameters could not be tied to a model at all
        $leaks = [];      // "METHOD uri -> status"
        $inconclusive = []; // answered 422 (validation first): neither proof nor leak
        $requests = 0;
        DB::statement('PRAGMA foreign_keys = OFF'); // lets a made-up victim row point at parents we did not bother to create

        $this->actingAs($actor, 'web');
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'app/') || ! str_contains($uri, '{') || str_contains($uri, 'logout')) {
                continue;
            }

            // which route parameters are Eloquent models: by type-hint, else by naming convention
            // (plenty of controllers take `int $sale` and look it up themselves — the riskiest shape)
            $models = [];
            try {
                foreach ($route->signatureParameters(['subClass' => Model::class]) as $param) {
                    $models[$param->getName()] = $param->getType()->getName();
                }
            } catch (\Throwable) {
            }
            $segments = explode('/', $uri);
            foreach ($route->parameterNames() as $name) {
                if (! isset($models[$name])) {
                    $guess = $this->guessModel($name, $segments);
                    if ($guess) {
                        $models[$name] = $guess;
                    }
                }
            }
            if (! $models) {
                $unmapped[] = $uri;

                continue;
            }

            // a real row of shop B for each (made up where shop B has none); any other parameter gets a harmless 1
            $url = $uri;
            $aimedAt = [];
            $missing = false;
            foreach ($route->parameterNames() as $name) {
                if (isset($models[$name])) {
                    $row = $this->victimRow($models[$name]);
                    if (! $row) {
                        $missing = true;
                        break;
                    }
                    $value = (string) $row->getRouteKey();
                    $aimedAt[] = class_basename($models[$name]);
                } else {
                    $value = '1';
                }
                $url = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', $value, $url);
            }
            if ($missing) {
                $skipped[] = $uri;

                continue;
            }
            $url = preg_replace('/\{[^}]+\}/', '', $url);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if ($getOnly && $method !== 'GET') {
                    continue;
                }
                $plan[] = [$method, $url, $aimedAt];
            }
        }

        // phase 2: only now, with every target row (real or made up) in place, take the fingerprint and attack
        $before = $this->victimChecksum();
        foreach ($plan as [$method, $url, $aimedAt]) {
            \App\Support\Tenancy::clear();
            \App\Support\Tenancy::forgetShopCache();
            $status = $this->json($method, '/'.ltrim($url, '/'))->status();
            $statuses[] = $status;
            $requests++;
            foreach ($aimedAt as $cls) {
                $probed[$cls] = ($probed[$cls] ?? 0) + 1;
            }
            if ($status === 422) {
                // validation runs before the controller looks the row up, so this proves nothing either way;
                // the valid-payload attacks below cover the money-moving ones
                $inconclusive[] = "{$method} /{$url}";
            } elseif (! in_array($status, [403, 404], true)) {
                $leaks[] = "{$method} /{$url} -> {$status}";
            }
        }

        return ['requests' => $requests, 'leaks' => $leaks, 'inconclusive' => $inconclusive, 'skipped' => $skipped, 'unmapped' => $unmapped,
            'probed' => $probed, 'statuses' => $statuses, 'before' => $before, 'after' => $this->victimChecksum()];
    }

    public function test_a_stranger_owner_gets_403_or_404_from_every_route_that_binds_one_of_another_shops_rows(): void
    {
        $this->seedVictim();
        $r = $this->probe($this->attacker, false);

        @file_put_contents(storage_path('logs/p3_idor_coverage.json'), json_encode([
            'requests' => $r['requests'], 'models_aimed_at' => $r['probed'], 'routes_without_a_victim_row' => $r['skipped'], 'routes_not_mapped_to_a_model' => $r['unmapped'], 'answered_422_inconclusive' => $r['inconclusive'], 'leaks' => $r['leaks'],
        ], JSON_PRETTY_PRINT));

        $this->assertGreaterThan(100, $r['requests'], 'the probe must really have covered the application ('.$r['requests'].' requests)');
        $this->assertSame([], $r['leaks'], "routes that answered a stranger about another shop's row:\n".implode("\n", $r['leaks']));
        $this->assertSame($r['before'], $r['after'], 'shop B\'s data changed while a stranger was hammering it');
    }

    /**
     * Positive control. If the probe only ever saw 404s because its URLs were wrong, "no leaks" would mean nothing.
     * The same GET probe, run by shop B's own owner, must reach real pages for most of the rows it aims at.
     */
    public function test_control_the_same_probe_run_by_the_rightful_owner_reaches_real_pages(): void
    {
        $this->seedVictim();
        $r = $this->probe($this->owner, true);

        $reached = count(array_filter($r['statuses'], fn ($s) => $s >= 200 && $s < 400));
        $notFound = count(array_filter($r['statuses'], fn ($s) => $s === 404));
        $this->assertGreaterThanOrEqual(15, $r['requests'], 'the control must have probed GET routes');
        $this->assertGreaterThan($notFound, $reached, "the rightful owner should reach more pages than get 404 ({$reached} reached, {$notFound} not found of {$r['requests']})");
    }

    /**
     * Ids that travel in the BODY (not the URL) are invisible to the route probe. Each request below is a perfectly
     * valid payload for the stranger's own screen, except that every id points at shop B.
     */
    public function test_valid_requests_carrying_another_shops_ids_in_the_body_change_nothing_anywhere(): void
    {
        $this->seedVictim();
        $p = \App\Models\Product::withoutGlobalScopes()->where('name', 'Victim Shirt')->firstOrFail();
        $v = \App\Models\ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->firstOrFail();
        $plain = \App\Models\Product::withoutGlobalScopes()->where('name', 'Victim Plain')->firstOrFail();
        $cust = \App\Models\Customer::withoutGlobalScopes()->where('phone', '01788888888')->firstOrFail();
        $sup = \App\Models\Supplier::withoutGlobalScopes()->where('name', 'Victim Supplier')->firstOrFail();
        $sale = \App\Models\Sale::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $item = (int) DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->value('id');
        $pending = \App\Models\Purchase::withoutGlobalScopes()->where('status', 'pending')->first();

        $counts = fn () => collect(['sales', 'sale_items', 'sale_payments', 'payments', 'purchases', 'supplier_returns', 'damages', 'returns', 'expenses', 'stock_intakes', 'product_sources', 'stock_transfers'])
            ->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $beforeVictim = $this->victimChecksum();
        $beforeCounts = $counts();
        $beforeAttacker = [(float) $this->attackerShop->fresh()->cash_balance, (float) $this->attackerShop->fresh()->bank_balance];

        $attacks = [
            'checkout of the victim\'s variant' => ['POST', '/app/pos/checkout', ['items' => [['product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]]]],
            'checkout of the victim\'s plain product' => ['POST', '/app/pos/checkout', ['items' => [['product_id' => $plain->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 50]]]],
            'a sale on the victim\'s customer' => ['POST', '/app/pos/checkout', ['items' => [], 'customer_id' => $cust->id, 'payments' => []]],
            'return against the victim\'s bill' => ['POST', '/app/returns/process', ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'QA', 'lines' => [['sale_item_id' => $item, 'qty' => 1, 'condition' => 'resalable']]]],
            'void of the victim\'s bill' => ['DELETE', "/app/sales/{$sale->id}", ['reason' => 'QA stranger test']],
            'payment on the victim\'s customer' => ['POST', "/app/customers/{$cust->id}/payments", ['amount' => 10, 'method' => 'cash']],
            'payment to the victim\'s supplier' => ['POST', "/app/suppliers/{$sup->id}/payments", ['amount' => 10, 'method' => 'cash']],
            'supplier return of the victim\'s stock' => ['POST', '/app/supplier-returns', ['supplier_id' => $sup->id, 'settlement_method' => 'cash', 'reason' => 'QA', 'lines' => [['product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 1, 'amount' => 10]]]],
            'purchase into the victim\'s product' => ['POST', '/app/purchases', ['amount' => 10, 'method' => 'cash', 'status' => 'received', 'supplier_id' => $sup->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 1, 'cost' => 10]],
            'receive the victim\'s pending purchase' => ['POST', '/app/purchases/'.($pending?->id ?? 1).'/receive', []],
            'damage the victim\'s stock' => ['POST', '/app/damages', ['product_id' => $plain->id, 'qty' => 1, 'reason' => 'QA']],
            'stock count of the victim\'s size' => ['POST', '/app/stock-count', ['counts' => [['product_id' => $p->id, 'product_variant_id' => $v->id, 'counted' => 0]]]],
            'transfer the victim\'s stock to the stranger' => ['POST', '/app/stock-transfers', ['to_shop_id' => $this->attackerShop->id, 'product_id' => $p->id, 'lines' => [['product_variant_id' => $v->id, 'qty' => 1]]]],
            'stock-in on the victim\'s product' => ['POST', "/app/products/{$plain->id}/stock-in", ['qty' => 5, 'cost' => 1]],
            'edit the victim\'s product' => ['PUT', "/app/products/{$plain->id}", ['name' => 'Hacked', 'cost' => 1, 'price' => 1, 'stock' => 0]],
        ];

        $answers = [];
        foreach ($attacks as $label => [$method, $uri, $body]) {
            \App\Support\Tenancy::clear();
            \App\Support\Tenancy::forgetShopCache();
            $r = $this->actingAs($this->attacker, 'web')->json($method, $uri, $body);
            $answers[$label] = $r->status().($r->status() === 302 ? ' '.json_encode(array_keys(session()->all() ? array_intersect_key(session()->all(), ['success' => 1, 'error' => 1]) : [])) : '');
        }

        $this->assertSame($beforeVictim, $this->victimChecksum(), "a stranger changed shop B's books. Answers: ".json_encode($answers));
        $this->assertSame($beforeCounts, $counts(), "a stranger created rows somewhere (referencing shop B's ids). Answers: ".json_encode($answers));
        $fresh = $this->attackerShop->fresh();
        $this->assertEquals($beforeAttacker, [(float) $fresh->cash_balance, (float) $fresh->bank_balance], 'the stranger\'s own cash/bank moved: '.json_encode($answers));
    }

    // ---------------------------------------------------------------- 3. branch isolation

    public function test_a_sibling_branch_cannot_void_read_or_change_the_main_shops_bill_or_product(): void
    {
        [$branch, $branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $this->shop->id]);
        foreach (['returns', 'accounts', 'product_variants'] as $f) {
            $this->grantFeature($branch, $f);
        }
        $p = $this->p3Product('Main Shirt', 40, 100, 20);
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
        $before = $this->victimChecksum();

        \App\Support\Tenancy::clear();
        \App\Support\Tenancy::forgetShopCache();
        $this->actingAs($branchOwner, 'web');

        $this->assertContains($this->json('DELETE', "/app/sales/{$sale->id}", ['reason' => 'QA stranger'])->status(), [403, 404], 'void');
        \App\Support\Tenancy::clear();
        \App\Support\Tenancy::forgetShopCache();
        $this->assertContains($this->json('PUT', "/app/products/{$p->id}", ['name' => 'Hacked', 'cost' => 1, 'price' => 1, 'stock' => 0])->status(), [403, 404], 'product update');
        \App\Support\Tenancy::clear();
        \App\Support\Tenancy::forgetShopCache();
        $this->assertContains($this->json('POST', "/app/products/{$p->id}/stock-in", ['qty' => 5, 'cost' => 1])->status(), [403, 404], 'stock-in');

        $this->assertSame($before, $this->victimChecksum(), 'a sibling branch changed the main shop\'s books');
    }
}
