<?php

namespace App\Console\Commands;

use App\Http\Controllers\App\AccountsController;
use App\Http\Controllers\App\OnlineOrderController;
use App\Http\Controllers\App\PosController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ReturnController;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\ArticleSerial;
use App\Support\BarcodeResolver;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Real-path self-check of every PAID shop: opens the screens the way an owner
 * does (POS, Stock, Accounts, Returns/Exchange lookup, online-order scan,
 * supplier report, new-article serial) against the shop's REAL data, checking
 * that the answers are right (every scan kind resolves, supplier totals add
 * up to the stock value, no suggested serial is already live) and that they
 * are fast (query count + milliseconds). Read-only.
 *
 * Built after Baby Park's November 2026 run of "scan / slow / numbers do not
 * add up" reports — each had passed its unit tests and was caught by the
 * owner. Exit code 1 on any FAIL or SLOW so a deploy script can stop on it.
 */
class ZaylotixSelfCheck extends Command
{
    protected $signature = 'zaylotix:self-check {--shop=* : only these shop ids (default: every paid shop)} {--sweep : also open EVERY parameterless GET page of each shop through the real HTTP kernel and flag any 5xx or slow page}';

    protected $description = 'Read-only real-data check of every paid shop: scans, returns lookup, supplier report, serials, and speed.';

    private int $fail = 0;

    private int $slow = 0;

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->option('shop'))
            ?: Shop::withoutGlobalScopes()->where('account_type', 'paid')->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            $shop = Shop::withoutGlobalScopes()->find($id);
            if (! $shop || ! User::where('shop_id', $id)->where('role', 'owner')->exists()) {
                $this->warn("shop #{$id}: not found or has no owner — skipped");

                continue;
            }
            $this->newLine();
            $this->info("######## SHOP #{$id} {$shop->name} (".($shop->parent_shop_id ? "branch of {$shop->parent_shop_id}" : 'main').') ########');
            $this->checkShop($shop);
            if ($this->option('sweep')) {
                $this->sweepPages($shop);
            }
        }

        $this->newLine();
        $this->line("SELF-CHECK: {$this->fail} FAIL, {$this->slow} SLOW");

        return ($this->fail || $this->slow) ? self::FAILURE : self::SUCCESS;
    }

    private function ok(string $what, bool $pass, string $detail = ''): void
    {
        $this->line(($pass ? '    PASS ' : '    FAIL ').$what.($detail ? " — {$detail}" : ''));
        if (! $pass) {
            $this->fail++;
        }
    }

    private function timed(string $what, callable $fn, int $maxMs, int $maxQueries)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $out = $fn();
        $ms = (int) round((microtime(true) - $t) * 1000);
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();
        $bad = $ms > $maxMs || $q > $maxQueries;
        $this->line(($bad ? '    SLOW ' : '    FAST ')."{$what}: {$q} queries, {$ms} ms".($bad ? " (limit {$maxQueries} q / {$maxMs} ms)" : ''));
        if ($bad) {
            $this->slow++;
        }

        return $out;
    }

    private function props($response, Request $req): ?array
    {
        return json_decode($response->toResponse($req)->getContent(), true)['props'] ?? null;
    }

    /** @return array{0: User, 1: Request} */
    private function asOwner(int $shopId): array
    {
        Tenancy::forgetShopCache();
        Tenancy::set($shopId);
        $u = User::where('shop_id', $shopId)->where('role', 'owner')->first();
        Auth::guard('web')->setUser($u);
        $req = Request::create('/x', 'GET');
        $req->setUserResolver(fn () => $u);
        $req->headers->set('X-Inertia', 'true');
        $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
        app()->instance('request', $req);

        return [$u, $req];
    }

    /**
     * Every GET page under /app that needs no URL parameter, opened as the
     * shop's owner through the real HTTP kernel (middleware, policies,
     * Inertia and all) — the way a tap on any menu button does. A 5xx, an
     * uncaught error, or a slow page (>1.5 s / >120 queries) fails the check.
     * Read-only by construction: GET routes only, and the few that stream a
     * file or end the session are skipped.
     */
    private function sweepPages(Shop $shop): void
    {
        $this->line('  [sweep: every page]');
        $skip = '/(logout|export|download|pdf|print|label|backup|qr|whatsapp|stream|csv|template|sample|callback)/i';
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'app/') && ! str_contains($r->uri(), '{') && ! preg_match($skip, $r->uri()))
            ->map(fn ($r) => '/'.$r->uri())->unique()->sort()->values();

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $bad = 0;
        $opened = 0;
        foreach ($uris as $uri) {
            [$u] = $this->asOwner($shop->id);
            $req = Request::create($uri, 'GET');
            $req->headers->set('X-Inertia', 'true');
            $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
            $req->headers->set('Accept', 'text/html, application/xhtml+xml');
            $req->setUserResolver(fn () => $u);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $t = microtime(true);
            try {
                $res = $kernel->handle($req);
                $status = $res->getStatusCode();
                $err = null;
            } catch (\Throwable $e) {
                $status = 500;
                $err = get_class($e).': '.\Illuminate\Support\Str::limit($e->getMessage(), 120);
            }
            $ms = (int) round((microtime(true) - $t) * 1000);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            // 403/404/302 are normal answers (feature off, permission, redirect); only a server error or a slow page is a defect
            if ($status >= 500) {
                $this->line("    FAIL {$uri} → {$status} ".($err ?? ''));
                $this->fail++;
                $bad++;
            } elseif ($ms > 1500 || $q > 120) {
                $this->line("    SLOW {$uri} → {$status}: {$q} queries, {$ms} ms");
                $this->slow++;
                $bad++;
            }
            if ($status === 200) {
                $opened++;
            }
        }
        $this->line("    ".count($uris)." pages tried, {$opened} opened, {$bad} problem(s)");
    }

    private function checkShop(Shop $shop): void
    {
        $id = $shop->id;
        $features = $shop->features()->pluck('key');

        $this->line('  [POS open]');
        [, $req] = $this->asOwner($id);
        $props = $this->props($this->timed('PosController::index', fn () => app(PosController::class)->index($req), 900, 60), $req);
        $n = count($props['products'] ?? []);
        $kb = round(strlen(json_encode($props)) / 1024);
        $this->ok("POS catalogue loads (mode ".($props['catalogMode'] ?? '?').", {$n} products, {$kb} KB)", $n > 0 || ($props['catalogMode'] ?? '') === 'search');
        $this->ok('no cost price reaches the cashier screen', ! collect($props['products'] ?? [])->contains(fn ($p) => array_key_exists('cost', $p)));

        $this->line('  [scan: every kind of code resolves]');
        $variants = ProductVariant::withoutGlobalScopes()->where('shop_id', $id)->whereNull('deleted_at')->whereNotNull('barcode')->where('stock', '>', 0)->inRandomOrder()->limit(5)->get();
        foreach ($variants as $v) {
            $a = BarcodeResolver::resolve($v->barcode);
            $b = BarcodeResolver::resolve('ZV'.$v->id);
            $this->ok("variant #{$v->id}: its barcode and ZV{$v->id} resolve to it", ($a['found'] ?? false) && ($a['variant_id'] ?? 0) == $v->id && ($b['found'] ?? false) && ($b['variant_id'] ?? 0) == $v->id);
        }
        $sibId = $shop->parent_shop_id ?: Shop::withoutGlobalScopes()->where('parent_shop_id', $id)->value('id');
        if ($sibId) {
            $sv = ProductVariant::withoutGlobalScopes()->where('shop_id', $sibId)->whereNull('deleted_at')->whereNotNull('barcode')->inRandomOrder()->limit(8)->get();
            $miss = $sv->filter(fn ($v) => ! (BarcodeResolver::resolve('ZV'.$v->id)['found'] ?? false))->count();
            $this->ok("sibling-branch (#{$sibId}) stickers resolve here", $miss === 0, "{$sv->count()} sampled, {$miss} not found");
        }
        $this->ok('an unknown code is "not found" (no crash)', BarcodeResolver::resolve('NO-SUCH-CODE-999')['found'] === false);

        $this->line('  [exchange / return screen]');
        $sale = Sale::withoutGlobalScopes()->where('shop_id', $id)->whereNull('voided_at')->orderByDesc('id')->first();
        if ($sale) {
            [, $r2] = $this->asOwner($id);
            $r2->query->set('q', $sale->invoice_no);
            $rc = app(ReturnController::class);
            $res = $this->timed("lookup by invoice {$sale->invoice_no}", fn () => $rc->lookup($r2)->getData(true), 800, 40);
            $this->ok('invoice lookup finds the bill', count($res['sales'] ?? []) >= 1);
            $item = DB::table('sale_items')->where('sale_id', $sale->id)->whereNotNull('product_variant_id')->first();
            $vv = $item ? ProductVariant::withoutGlobalScopes()->find($item->product_variant_id) : null;
            if ($vv && $vv->barcode) {
                $r3 = clone $r2;
                $r3->query->set('q', $vv->barcode);
                $res3 = $rc->lookup($r3)->getData(true);
                $this->ok('scanning a sold item finds its bill and shows the selling shop', collect($res3['sales'] ?? [])->contains(fn ($s) => ($s['shop_name'] ?? null) !== null) && ($res3['resolved'] ?? null) !== null);
            }
            $never = ProductVariant::withoutGlobalScopes()->where('shop_id', $id)->whereNull('deleted_at')->whereNotNull('barcode')
                ->whereNotIn('id', DB::table('sale_items')->whereNotNull('product_variant_id')->select('product_variant_id'))->first();
            if ($never) {
                $r4 = clone $r2;
                $r4->query->set('q', $never->barcode);
                $res4 = $rc->lookup($r4)->getData(true);
                $this->ok('a never-sold item says "not sold" (recognised, no bills)', empty($res4['sales']) && ! empty($res4['resolved']));
            }
        } else {
            $this->line('    (no sales yet — skipped)');
        }

        $this->line('  [online order scan endpoint]');
        if ($features->contains('online_business')) {
            [, $r5] = $this->asOwner($id);
            $ob = app(OnlineOrderController::class);
            $v = ProductVariant::withoutGlobalScopes()->where('shop_id', $id)->whereNull('deleted_at')->whereNotNull('barcode')->first();
            if ($v) {
                $this->ok('online-order barcode endpoint resolves a real sticker', $ob->barcode($v->barcode)->getStatusCode() === 200);
            }
            $this->timed('OnlineOrderController::create (scan form data)', fn () => $ob->create(), 900, 40);
        } else {
            $this->line('    (online_business not enabled for this shop)');
        }

        $this->line('  [stock / accounts / supplier report]');
        [, $r6] = $this->asOwner($id);
        $this->timed('Stock page', fn () => app(ProductController::class)->index($r6), 1500, 80);
        $this->asOwner($id);
        $this->timed('Accounts page', fn () => app(AccountsController::class)->index(), 800, 40);
        if ($features->contains('suppliers') || $features->contains('source_lines')) {
            [, $r7] = $this->asOwner($id);
            $page = null;
            $this->timed('Supplier report (all time)', function () use (&$page, $r7) {
                $page = $this->props(app(AccountsController::class)->stockValueBySupplier($r7), $r7);
            }, 1500, 120);
            $rows = collect($page['rows'] ?? []);
            $sv = new \ReflectionMethod(AccountsController::class, 'stockValue');
            $sv->setAccessible(true);
            $val = $sv->invoke(new AccountsController);
            $this->ok('supplier rows add up to the real stock value', abs($rows->sum('value') - $val) < 1, number_format($rows->sum('value')).' vs '.number_format($val));
            $this->ok('every row: total received = in stock + sold', $rows->every(fn ($r) => abs($r['everQty'] - ($r['qty'] + $r['soldQty'])) < 0.01));
            $this->ok('branch split adds up to sold', $rows->every(fn ($r) => abs(collect($r['branches'])->sum('value') - $r['sold']) < 0.01));
        } else {
            $this->line('    (suppliers not enabled for this shop)');
        }

        $this->line('  [new article: serial suggestions]');
        if ($features->contains('article_series')) {
            $family = array_filter(array_merge([$id], Shop::withoutGlobalScopes()->where('parent_shop_id', $id)->pluck('id')->all(), $shop->parent_shop_id ? [$shop->parent_shop_id] : []));
            $bad = 0;
            $cats = DB::table('product_categories')->where('shop_id', $id)->whereNotNull('code')->where('code', '!=', '')->get(['code', 'serial_start']);
            foreach ($cats as $c) {
                $next = ArticleSerial::nextFree($shop, $c->code, $c->serial_start);
                if (Product::withoutGlobalScopes()->whereIn('shop_id', $family)->whereNull('deleted_at')->where('name', "{$c->code}-{$next}")->exists()) {
                    $bad++;
                }
            }
            $this->ok("no category suggests a number that is already live ({$cats->count()} categories)", $bad === 0, "{$bad} bad");
        } else {
            $this->line('    (article_series not enabled for this shop)');
        }
    }
}
