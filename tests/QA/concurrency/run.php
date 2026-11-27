<?php

/**
 * QA Phase 3 — G. Concurrency scenarios against the QA database (zaylotix_qa, Baby Park main = shop 9, branch = shop 10).
 *
 *   php tests/QA/concurrency/run.php [scenario ...] [--rounds=N]
 *
 * Each scenario builds its own fresh articles, races N real processes (worker.php) on them, then checks invariants
 * with raw SQL — never through the application's own reports. It NEVER touches any database but zaylotix_qa.
 * Invariants checked everywhere: no HTTP 5xx / uncaught exception, stock never negative, no lost update.
 */
foreach (['DB_DATABASE' => 'zaylotix_qa', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'file'] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::getDatabaseName() !== 'zaylotix_qa') {
    fwrite(STDERR, "REFUSING: not the QA database\n");
    exit(2);
}

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const MAIN = 9;
const BRANCH = 10;
const OWNER_MAIN = 9;      // users.id of the shop-9 owner
const CASHIER_BRANCH = 10; // users.id of the shop-10 cashier

$argvRest = array_slice($argv, 1);
$rounds = 4;
$wanted = [];
foreach ($argvRest as $a) {
    if (preg_match('/^--rounds=(\d+)$/', $a, $m)) {
        $rounds = (int) $m[1];
    } else {
        $wanted[] = $a;
    }
}

// ------------------------------------------------------------------ helpers

function spawn(array $jobs, float $leadSeconds = 5.0): array
{
    $start = microtime(true) + $leadSeconds;
    $procs = [];
    foreach ($jobs as $i => $job) {
        $job['start'] = $start;
        $job['label'] ??= "w{$i}";
        $cmd = [PHP_BINARY, __DIR__.'/worker.php', json_encode($job)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($p);
        $row = json_decode(trim(strtok($stdout, "\n")), true);
        $out[] = $row ?: ['status' => 598, 'body' => 'worker produced no result: '.substr($stderr.$stdout, 0, 300)];
    }

    return $out;
}

function newArticle(string $tag, int $stock, float $price = 100, float $cost = 40): array
{
    Tenancy::clear();
    Tenancy::set(MAIN);
    $p = Product::create(['shop_id' => MAIN, 'name' => $tag.'-'.Str::random(5), 'cost' => $cost, 'price' => $price, 'stock' => 0]);
    $v = ProductVariant::create(['shop_id' => MAIN, 'product_id' => $p->id, 'size' => '40', 'color' => 'Black', 'stock' => $stock, 'price' => $price, 'cost' => $cost]);
    $p->forceFill(['stock' => $stock])->save();

    return [$p->id, $v->id];
}

/** every copy of the article in the pooled business (main + branch copy) */
function stockEverywhere(int $masterProductId): array
{
    $ids = DB::table('products')->where('id', $masterProductId)->orWhere('master_product_id', $masterProductId)->pluck('id')->all();
    $out = [];
    foreach ($ids as $pid) {
        $out["p{$pid}"] = (float) DB::table('products')->where('id', $pid)->value('stock');
        foreach (DB::table('product_variants')->where('product_id', $pid)->whereNull('deleted_at')->get() as $v) {
            $out["v{$v->id}"] = (float) $v->stock;
        }
    }

    return $out;
}

function cash(int $shop): float
{
    return round((float) DB::table('shops')->where('id', $shop)->value('cash_balance'), 2);
}

function checkoutJob(int $user, int $pid, int $vid, int $qty, float $price, ?string $uuid = null): array
{
    return ['user' => $user, 'method' => 'POST', 'uri' => '/app/pos/checkout', 'body' => array_filter([
        'items' => [['product_id' => $pid, 'product_variant_id' => $vid, 'qty' => $qty]],
        'payments' => [['method' => 'cash', 'amount' => $price * $qty]],
        'client_uuid' => $uuid,
    ], fn ($v) => $v !== null)];
}

/** rings ONE bill (alone, no race) and returns its id, looked up by the uuid we sent */
function makeSale(int $pid, int $vid, int $qty): int
{
    $uuid = (string) Str::uuid();
    spawn([checkoutJob(OWNER_MAIN, $pid, $vid, $qty, 100, $uuid)], 3);

    return (int) DB::table('sales')->where('client_uuid', $uuid)->value('id');
}

function countStatus(array $results, callable $pred): int
{
    return count(array_filter($results, fn ($r) => $pred((int) $r['status'])));
}

function fiveHundreds(array $results): array
{
    return array_values(array_filter($results, fn ($r) => (int) $r['status'] >= 500));
}

// ------------------------------------------------------------------ scenarios: each returns a list of violation strings

$scenarios = [];

/** S1: one piece left, N cashiers of ONE shop all sell it at the same instant */
$scenarios['S1_last_piece_same_shop'] = function () {
    [$pid, $vid] = newArticle('CONC-S1', 1);
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => checkoutJob(OWNER_MAIN, $pid, $vid, 1, 100, (string) Str::uuid()), range(1, 6)));
    $bad = [];
    $ok = countStatus($res, fn ($s) => $s === 200);
    if ($ok !== 1) {
        $bad[] = "exactly one sale must succeed, {$ok} did (statuses ".implode(',', array_column($res, 'status')).')';
    }
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if ($s != 0.0) {
            $bad[] = "{$k} stock is {$s}, expected 0 (oversold or double-counted)";
        }
    }
    $sold = (int) DB::table('sale_items')->where('product_variant_id', $vid)->sum('qty');
    if ($sold !== 1) {
        $bad[] = "{$sold} pieces were sold of 1 that existed";
    }
    if (abs(cash(MAIN) - $cash0 - 100) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' instead of 100';
    }

    return $bad;
};

/** S1b: one piece left in a POOLED business, sold from BOTH branches at the same instant */
$scenarios['S1b_last_piece_two_branches'] = function () {
    [$pid, $vid] = newArticle('CONC-S1B', 1);
    $branchVariant = DB::table('product_variants')->whereIn('product_id', DB::table('products')->where('master_product_id', $pid)->pluck('id'))->value('id');
    $branchProduct = (int) DB::table('products')->where('master_product_id', $pid)->value('id');
    if (! $branchVariant) {
        return ['fixture: the branch copy of the article was not created (pool hooks)'];
    }
    $jobs = [];
    foreach (range(1, 3) as $i) {
        $jobs[] = checkoutJob(OWNER_MAIN, $pid, $vid, 1, 100, (string) Str::uuid());
        $jobs[] = checkoutJob(CASHIER_BRANCH, $branchProduct, (int) $branchVariant, 1, 100, (string) Str::uuid());
    }
    $res = spawn($jobs);
    $bad = [];
    $ok = countStatus($res, fn ($s) => $s === 200);
    if ($ok !== 1) {
        $bad[] = "pooled last piece: exactly one sale must succeed across both shops, {$ok} did (".implode(',', array_column($res, 'status')).')';
    }
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if ($s != 0.0) {
            $bad[] = "{$k} stock is {$s}, expected 0";
        }
    }

    return $bad;
};

/** S2: the SAME bill (same client_uuid) double/triple/…-tapped */
$scenarios['S2_duplicate_request_same_uuid'] = function () {
    [$pid, $vid] = newArticle('CONC-S2', 10);
    $cash0 = cash(MAIN);
    $uuid = (string) Str::uuid();
    $res = spawn(array_map(fn ($i) => checkoutJob(OWNER_MAIN, $pid, $vid, 1, 100, $uuid), range(1, 6)));
    $bad = [];
    $sales = (int) DB::table('sales')->where('client_uuid', $uuid)->count();
    if ($sales !== 1) {
        $bad[] = "{$sales} bills exist for one client_uuid";
    }
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != 9.0 && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected 9 (taken once)";
        }
    }
    if (abs(cash(MAIN) - $cash0 - 100) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' instead of 100 (taken once)';
    }
    $codes = array_count_values(array_column($res, 'status'));
    $GLOBALS['notes'][] = 'S2 answers: '.json_encode($codes);

    return $bad;
};

/** S3: the same bill voided by N tabs at once */
$scenarios['S3_void_same_bill_in_parallel'] = function () {
    [$pid, $vid] = newArticle('CONC-S3', 10);
    $saleId = makeSale($pid, $vid, 2);
    $cash1 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'DELETE', 'uri' => "/app/sales/{$saleId}", 'body' => ['reason' => 'QA parallel void']], range(1, 5)));
    $bad = [];
    if (! $saleId) {
        return ['fixture: the sale was not created'];
    }
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    $v = (int) DB::table('activity_logs')->where('action', 'sale.void')->where('subject_id', $saleId)->count();
    if ($v !== 1) {
        $bad[] = "{$v} void log entries for one bill";
    }
    if (abs(cash(MAIN) - $cash1 + 200) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash1).' instead of -200 (reversed once)';
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != 10.0 && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected 10 (restored once)";
        }
    }

    return $bad;
};

/** S4: the single piece of a bill returned by N tabs at once */
$scenarios['S4_return_same_piece_in_parallel'] = function () {
    [$pid, $vid] = newArticle('CONC-S4', 10);
    $saleId = makeSale($pid, $vid, 1);
    if (! $saleId) {
        return ['fixture: the sale was not created'];
    }
    $itemId = (int) DB::table('sale_items')->where('sale_id', $saleId)->value('id');
    $cash1 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/returns/process', 'body' => ['sale_id' => $saleId, 'action' => 'refund', 'reason' => 'QA', 'lines' => [['sale_item_id' => $itemId, 'qty' => 1, 'condition' => 'resalable']]]], range(1, 5)));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    $ok = countStatus($res, fn ($s) => $s === 200);
    $rows = (int) DB::table('returns')->where('sale_id', $saleId)->count();
    $returned = (float) DB::table('returns')->where('sale_id', $saleId)->sum('qty');
    if ($ok !== 1 || $returned != 1.0) {
        $bad[] = "one piece sold: {$ok} returns accepted, {$returned} pieces recorded in {$rows} rows (statuses ".implode(',', array_column($res, 'status')).')';
    }
    if (abs(cash(MAIN) - $cash1 + 100) > 0.001) {
        $bad[] = 'refund moved cash by '.(cash(MAIN) - $cash1).' instead of -100 (refunded once)';
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != 10.0 && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected 10 (restocked once)";
        }
    }

    return $bad;
};

/** S5: a return and a void of the same bill at the same instant */
$scenarios['S5_return_vs_void_same_bill'] = function () {
    [$pid, $vid] = newArticle('CONC-S5', 10);
    $cash0 = cash(MAIN);
    $saleId = makeSale($pid, $vid, 3);
    if (! $saleId) {
        return ['fixture: the sale was not created'];
    }
    $itemId = (int) DB::table('sale_items')->where('sale_id', $saleId)->value('id');
    $res = spawn([
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/returns/process', 'body' => ['sale_id' => $saleId, 'action' => 'refund', 'reason' => 'QA', 'lines' => [['sale_item_id' => $itemId, 'qty' => 2, 'condition' => 'resalable']]]],
        ['user' => OWNER_MAIN, 'method' => 'DELETE', 'uri' => "/app/sales/{$saleId}", 'body' => ['reason' => 'QA parallel void']],
    ]);
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    // whatever order they ran in, the customer is whole and the shop is whole: sold 3, then returned 2 and/or voided
    $voided = DB::table('sales')->where('id', $saleId)->value('voided_at') !== null;
    $retQty = (float) DB::table('returns')->where('sale_id', $saleId)->sum('qty');
    $expectedStock = $voided ? 10.0 : 10.0 - 3 + $retQty;
    $expectedCash = $voided ? $cash0 : $cash0 + 300 - 100 * $retQty;
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != $expectedStock && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected {$expectedStock} (voided=".(int) $voided.", returned {$retQty})";
        }
    }
    if (abs(cash(MAIN) - $expectedCash) > 0.001) {
        $bad[] = 'cash is '.cash(MAIN)." expected {$expectedCash} (voided=".(int) $voided.", returned {$retQty})";
    }
    $GLOBALS['notes'][] = 'S5 outcome: '.($voided ? 'void won' : 'return won').", returned {$retQty}, statuses ".implode(',', array_column($res, 'status'));

    return $bad;
};

/** S6: N purchases of the same size land together — no lost update */
$scenarios['S6_parallel_purchases_no_lost_update'] = function () {
    [$pid, $vid] = newArticle('CONC-S6', 5);
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/purchases', 'body' => ['amount' => 80, 'method' => 'cash', 'status' => 'received', 'product_id' => $pid, 'product_variant_id' => $vid, 'qty' => 2, 'cost' => 40]], range(1, 6)));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    foreach (stockEverywhere($pid) as $k => $s) {
        $expected = 5.0 + 2 * $ok;
        if (str_starts_with($k, 'v') && $s != $expected && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected {$expected} ({$ok} purchases accepted of 2 pieces each)";
        }
    }
    if (abs(cash(MAIN) - $cash0 + 80 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(-80 * $ok);
    }
    $GLOBALS['notes'][] = 'S6 answers: '.json_encode(array_count_values(array_column($res, 'status'))).", accepted {$ok}, size stock ".json_encode(array_filter(stockEverywhere($pid), fn ($k) => str_starts_with($k, 'v'), ARRAY_FILTER_USE_KEY));
    $msg = array_values(array_filter(array_map(fn ($r) => (int) $r['status'] >= 500 ? (json_decode($r['body'], true)['message'] ?? $r['body']) : null, $res)));
    if ($msg) {
        $GLOBALS['notes'][] = 'S6 the 5xx message the user would see: '.$msg[0];
    }
    $logged = DB::table('information_schema.processlist')->count(); // keeps the connection warm; value unused

    return $bad;
};

/** S8: several cash-moving screens at once on the SAME shop row (expenses) — is the drawer figure exact, and does anything crash? */
$scenarios['S8_parallel_expenses_cash_exact'] = function () {
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/expenses', 'body' => ['amount' => 25, 'method' => 'cash', 'category' => 'rent', 'note' => 'QA parallel']], range(1, 6)));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    if (abs(cash(MAIN) - $cash0 + 25 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(-25 * $ok)." ({$ok} accepted)";
    }
    $GLOBALS['notes'][] = 'S8 answers: '.json_encode(array_count_values(array_column($res, 'status')));

    return $bad;
};

/** S9: parallel supplier returns settled in cash (money IN on the shop row) */
$scenarios['S9_parallel_supplier_returns_cash'] = function () {
    [$pid, $vid] = newArticle('CONC-S9', 20);
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/supplier-returns', 'body' => ['settlement_method' => 'cash', 'reason' => 'QA', 'lines' => [['product_id' => $pid, 'product_variant_id' => $vid, 'qty' => 1, 'amount' => 40]]]], range(1, 6)));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != 20.0 - $ok && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected ".(20 - $ok)." ({$ok} accepted)";
        }
    }
    if (abs(cash(MAIN) - $cash0 - 40 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(40 * $ok)." ({$ok} accepted)";
    }
    $GLOBALS['notes'][] = 'S9 answers: '.json_encode(array_count_values(array_column($res, 'status')));

    return $bad;
};

/** S10: two or more cashiers collect dues from DIFFERENT customers at the same moment (the realistic busy-evening case) */
$scenarios['S10_parallel_dues_different_customers'] = function () {
    Tenancy::clear();
    Tenancy::set(MAIN);
    $ids = [];
    foreach (range(1, (int) (getenv('CONC_N') ?: 4)) as $i) {
        $ids[] = App\Models\Customer::create(['shop_id' => MAIN, 'name' => "CONC-S10-{$i}", 'phone' => '0178'.random_int(1000000, 9999999), 'due' => 50])->id;
    }
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($id) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/customers/{$id}/payments", 'body' => ['amount' => 20, 'method' => 'cash', 'client_uuid' => (string) Str::uuid()]], $ids));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    if (abs(cash(MAIN) - $cash0 - 20 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(20 * $ok)." ({$ok} accepted)";
    }
    foreach ($ids as $id) {
        $due = (float) DB::table('customers')->where('id', $id)->value('due');
        $paid = (float) DB::table('payments')->where('customer_id', $id)->whereNull('voided_at')->sum('amount');
        if (abs($due - (50 - $paid)) > 0.001) {
            $bad[] = "customer {$id}: due {$due} != 50 - paid {$paid}";
        }
    }
    $GLOBALS['notes'][] = 'S10 answers: '.json_encode(array_count_values(array_column($res, 'status')));

    return $bad;
};

/** S11: several supplier payments, to DIFFERENT suppliers, at once */
$scenarios['S11_parallel_supplier_payments'] = function () {
    Tenancy::clear();
    Tenancy::set(MAIN);
    $ids = [];
    foreach (range(1, 4) as $i) {
        $ids[] = App\Models\Supplier::create(['shop_id' => MAIN, 'name' => 'CONC-S11-'.Str::random(4), 'payable' => 100])->id;
    }
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($id) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/suppliers/{$id}/payments", 'body' => ['amount' => 30, 'method' => 'cash']], $ids));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    if (abs(cash(MAIN) - $cash0 + 30 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(-30 * $ok)." ({$ok} accepted)";
    }
    foreach ($ids as $id) {
        $paid = (float) DB::table('supplier_payments')->where('supplier_id', $id)->sum('amount');
        $payable = (float) DB::table('suppliers')->where('id', $id)->value('payable');
        if (abs($payable - (100 - $paid)) > 0.001) {
            $bad[] = "supplier {$id}: payable {$payable} != 100 - paid {$paid}";
        }
    }
    $GLOBALS['notes'][] = 'S11 answers: '.json_encode(array_count_values(array_column($res, 'status')));

    return $bad;
};

/** S12: stock-in paid in cash on several DIFFERENT sizes at once (the Stock page's own paid stock-in) */
$scenarios['S12_parallel_paid_stock_in'] = function () {
    Tenancy::clear();
    Tenancy::set(MAIN);
    $supplier = App\Models\Supplier::create(['shop_id' => MAIN, 'name' => 'CONC-S12-'.Str::random(4), 'payable' => 0])->id;
    $items = array_map(fn ($i) => newArticle('CONC-S12', 5), range(1, 4));
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($it) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/product-variants/{$it[1]}/stock-in", 'body' => ['qty' => 2, 'cost' => 40, 'supplier_id' => $supplier, 'payment_method' => 'cash']], $items));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $ok = countStatus($res, fn ($s) => $s < 400);
    $GLOBALS['notes'][] = 'S12 answers: '.json_encode(array_count_values(array_column($res, 'status'))).' cash moved '.(cash(MAIN) - $cash0).' (accepted '.$ok.')';
    if (abs(cash(MAIN) - $cash0 + 80 * $ok) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(-80 * $ok)." ({$ok} accepted, 2 pieces at 40 each)";
    }
    foreach ($items as [$pid, $vid]) {
        $stock = (float) DB::table('product_variants')->where('id', $vid)->value('stock');
        $expected = 5.0 + 2 * ((int) (bool) DB::table('stock_intakes')->where('product_variant_id', $vid)->exists());
        if ($stock != 5.0 && $stock != 7.0) {
            $bad[] = "size {$vid}: stock {$stock} is neither untouched nor +2";
        }
    }

    return $bad;
};

/** S13: every kind of cash-moving screen at the same instant, each on its own rows — the lock ORDER between screens must agree */
$scenarios['S13_mixed_screens_at_once'] = function () {
    Tenancy::clear();
    Tenancy::set(MAIN);
    [$p1, $v1] = newArticle('CONC-S13A', 10);
    [$p2, $v2] = newArticle('CONC-S13B', 10);
    [$p3, $v3] = newArticle('CONC-S13C', 10);
    $c1 = App\Models\Customer::create(['shop_id' => MAIN, 'name' => 'CONC-S13-1', 'phone' => '0175'.random_int(1000000, 9999999), 'due' => 80])->id;
    $c2 = App\Models\Customer::create(['shop_id' => MAIN, 'name' => 'CONC-S13-2', 'phone' => '0174'.random_int(1000000, 9999999), 'due' => 80])->id;
    $cash0 = cash(MAIN);
    $jobs = [
        checkoutJob(OWNER_MAIN, $p1, $v1, 1, 100, (string) Str::uuid()),
        checkoutJob(OWNER_MAIN, $p2, $v2, 1, 100, (string) Str::uuid()),
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/expenses', 'body' => ['amount' => 25, 'method' => 'cash', 'memo' => 'QA mix']],
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/expenses', 'body' => ['amount' => 15, 'method' => 'cash', 'memo' => 'QA mix']],
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/customers/{$c1}/payments", 'body' => ['amount' => 20, 'method' => 'cash', 'client_uuid' => (string) Str::uuid()]],
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/customers/{$c2}/payments", 'body' => ['amount' => 20, 'method' => 'cash', 'client_uuid' => (string) Str::uuid()]],
        ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/purchases', 'body' => ['amount' => 80, 'method' => 'cash', 'status' => 'received', 'product_id' => $p3, 'product_variant_id' => $v3, 'qty' => 2, 'cost' => 40]],
    ];
    $res = spawn($jobs);
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $expected = [0, 1]; // the two checkouts are +100 each
    $delta = 0.0;
    foreach ($res as $i => $r) {
        if ((int) $r['status'] >= 400) {
            continue;
        }
        $delta += [100, 100, -25, -15, 20, 20, -80][$i];
    }
    if (abs(cash(MAIN) - $cash0 - $delta) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0)." but the accepted requests add up to {$delta}";
    }
    $GLOBALS['notes'][] = 'S13 answers: '.json_encode(array_column($res, 'status'));

    return $bad;
};

/** S14: POS checkouts and cash supplier returns of the SAME article at once (opposite lock orders would deadlock) */
$scenarios['S14_pos_vs_supplier_return_same_article'] = function () {
    [$pid, $vid] = newArticle('CONC-S14', 12);
    $cash0 = cash(MAIN);
    $jobs = [];
    foreach (range(1, 3) as $i) {
        $jobs[] = checkoutJob(OWNER_MAIN, $pid, $vid, 1, 100, (string) Str::uuid());
        $jobs[] = ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => '/app/supplier-returns', 'body' => ['settlement_method' => 'cash', 'reason' => 'QA', 'lines' => [['product_id' => $pid, 'product_variant_id' => $vid, 'qty' => 1, 'amount' => 40]]]];
    }
    $res = spawn($jobs);
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 120);
    }
    $sold = 0;
    $returned = 0;
    foreach ($res as $i => $r) {
        if ((int) $r['status'] >= 400) {
            continue;
        }
        $i % 2 === 0 ? $sold++ : $returned++;
    }
    foreach (stockEverywhere($pid) as $k => $s) {
        if (str_starts_with($k, 'v') && $s != 12.0 - $sold - $returned && DB::table('product_variants')->where('id', (int) substr($k, 1))->value('product_id') == $pid) {
            $bad[] = "{$k} stock is {$s}, expected ".(12 - $sold - $returned)." ({$sold} sold, {$returned} returned to supplier)";
        }
    }
    if (abs(cash(MAIN) - $cash0 - 100 * $sold - 40 * $returned) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0).' expected '.(100 * $sold + 40 * $returned);
    }
    $GLOBALS['notes'][] = 'S14 answers: '.json_encode(array_column($res, 'status'));

    return $bad;
};

/** S7: customer payments racing on one due balance */
$scenarios['S7_parallel_customer_payments'] = function () {
    Tenancy::clear();
    Tenancy::set(MAIN);
    DB::table('shop_features')->insertOrIgnore([]); // no-op, keeps the intent visible: features are the QA shop's own
    $cust = App\Models\Customer::create(['shop_id' => MAIN, 'name' => 'CONC-S7', 'phone' => '0179'.random_int(1000000, 9999999), 'due' => 100]);
    $cash0 = cash(MAIN);
    $res = spawn(array_map(fn ($i) => ['user' => OWNER_MAIN, 'method' => 'POST', 'uri' => "/app/customers/{$cust->id}/payments", 'body' => ['amount' => 30, 'method' => 'cash', 'client_uuid' => (string) Str::uuid()]], range(1, 6)));
    $bad = [];
    foreach (fiveHundreds($res) as $r) {
        $bad[] = 'server error: '.$r['status'].' '.substr($r['body'], 0, 200);
    }
    $due = (float) DB::table('customers')->where('id', $cust->id)->value('due');
    $paid = (float) DB::table('payments')->where('customer_id', $cust->id)->whereNull('voided_at')->sum('amount');
    $rows = (int) DB::table('payments')->where('customer_id', $cust->id)->whereNull('voided_at')->count();
    if ($due < -0.001) {
        $bad[] = "the customer's due went negative: {$due}";
    }
    if (abs($due - (100 - $paid)) > 0.001) {
        $bad[] = "due {$due} != 100 - recorded payments {$paid} (lost update, {$rows} payment rows)";
    }
    if (abs(cash(MAIN) - $cash0 - $paid) > 0.001) {
        $bad[] = 'cash moved by '.(cash(MAIN) - $cash0)." but payments recorded total {$paid}";
    }
    $GLOBALS['notes'][] = 'S7 answers: '.json_encode(array_count_values(array_column($res, 'status'))).", due left {$due}, paid {$paid}";

    return $bad;
};

// ------------------------------------------------------------------ run

$GLOBALS['notes'] = [];
$totalViolations = 0;
$summary = [];
foreach ($scenarios as $name => $fn) {
    if ($wanted && ! array_filter($wanted, fn ($w) => str_starts_with($name, $w))) {
        continue;
    }
    $violations = [];
    $t = microtime(true);
    for ($r = 1; $r <= $rounds; $r++) {
        foreach ($fn() as $v) {
            $violations[] = "round {$r}: {$v}";
        }
    }
    $summary[$name] = ['rounds' => $rounds, 'violations' => count($violations), 'seconds' => round(microtime(true) - $t, 1)];
    $totalViolations += count($violations);
    echo ($violations ? '❌ ' : '✅ ').$name.'  ('.count($violations)." violations over {$rounds} rounds)\n";
    foreach (array_slice($violations, 0, 8) as $v) {
        echo '     - '.$v."\n";
    }
}
foreach (array_unique($GLOBALS['notes']) as $n) {
    echo 'note: '.$n."\n";
}
echo json_encode(['database' => DB::getDatabaseName(), 'summary' => $summary, 'violations' => $totalViolations])."\n";
exit($totalViolations ? 1 : 0);
