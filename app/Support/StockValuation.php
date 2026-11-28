<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place that says what the stock on the shelf is worth (QA D7, owner decision 2026-11-28).
 *
 * The stock-value card, its per-article detail, the supplier-wise report, the company dashboard, the partner and online
 * profit pages all read this, so they can never disagree.
 *
 * COSTING RULE
 *  - A shop that keeps supplier LOTS (feature `source_lines`): every piece that sits in a lot is valued at THAT LOT's own
 *    purchase cost — the same cost a sale of that lot snapshots onto its bill line (PosController::resolveCheckoutSource),
 *    so valuation and profit follow one costing logic. Per size (colour+size; a plain article is one "size"):
 *      lots hold   L pieces, the shelf holds S pieces
 *      L <= S : the lots at their own costs, plus the S − L untracked pieces at the size's current cost
 *      L >  S : the lots (e.g. a damage write-off never touched them) are scaled down pro rata to S
 *    A shop under a shared stock pool pools its lots across the branches (one shelf figure, lots live per branch).
 *  - Every other shop: unchanged — stock × the size's current cost (variant cost, else article cost), i.e. the latest
 *    purchase cost. Nothing is read from, or written to, any historical row: this is arithmetic at read time only.
 *
 * The supplier of a part is the lot's supplier; the untracked remainder belongs to the article's supplier (its master's,
 * for a branch copy) — "unknown" appears only when there really is no supplier on record.
 */
final class StockValuation
{
    /** Does this shop (or any shop of its stock pool) track supplier lots? */
    public static function usesLots(?int $shopId = null): bool
    {
        foreach (self::memberIds($shopId ?? Tenancy::id()) as $id) {
            $s = Shop::withoutGlobalScopes()->find($id);
            if ($s && $s->hasFeature('source_lines')) {
                return true;
            }
        }

        return false;
    }

    /** the shops whose stock is ONE figure with this one: itself, or its whole pool */
    public static function memberIds(int $shopId): array
    {
        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            return [$shopId];
        }
        $rootId = $shop->parent_shop_id ?? $shop->id;
        $root = Shop::withoutGlobalScopes()->find($rootId);
        $pooled = $shop->hasFeature('shared_stock_pool') || ($root && $root->hasFeature('shared_stock_pool'));

        return $pooled
            ? Shop::withoutGlobalScopes()->where('account_type', '!=', 'demo')
                ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_shop_id', $rootId))->pluck('id')->all()
            : [$shopId];
    }

    /** The value of the shop's stock. Shops without lots take the original single-query path, bit for bit. */
    public static function total(?int $shopId = null): float
    {
        $shopId ??= Tenancy::id();
        if (! self::usesLots($shopId)) {
            return self::mirrorTotal($shopId);
        }

        return (float) self::articles($shopId)->sum('value');
    }

    /** stock x current cost, per size (variant cost, else article cost) and per plain article — the pre-D7 formula */
    private static function mirrorTotal(int $shopId): float
    {
        $fromVariants = (float) DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.shop_id', $shopId)->whereNull('v.deleted_at')->whereNull('p.deleted_at')
            ->selectRaw('COALESCE(SUM(COALESCE(v.cost, p.cost) * v.stock), 0) as v')->value('v');

        $fromPlain = (float) DB::table('products as p')
            ->where('p.shop_id', $shopId)->whereNull('p.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_variants as v')->whereColumn('v.product_id', 'p.id')->whereNull('v.deleted_at'))
            ->selectRaw('COALESCE(SUM(p.cost * p.stock), 0) as v')->value('v');

        return $fromVariants + $fromPlain;
    }

    /**
     * What each supplier's goods on the shelf are worth (the same parts the supplier report is built from); a part with no
     * supplier on record is under the key 'unknown'.
     *
     * @return array<int|string, float>
     */
    public static function valueBySupplier(?int $shopId = null): array
    {
        $out = [];
        foreach (self::articles($shopId) as $article) {
            foreach ($article['parts'] as $part) {
                $key = $part['supplier_id'] ?: 'unknown';
                $out[$key] = ($out[$key] ?? 0.0) + $part['value'];
            }
        }

        return $out;
    }

    /**
     * Every article of the shop with its value, its sizes and the parts (lot / untracked remainder, by supplier) the value
     * is made of.
     *
     * @return Collection<int, array{product_id:int, name:string, emoji:?string, qty:float, value:float, sizes:array, parts:array}>
     */
    public static function articles(?int $shopId = null): Collection
    {
        $shopId ??= Tenancy::id();
        $memberIds = self::memberIds($shopId);
        $lotsOn = self::usesLots($shopId);

        $products = DB::table('products')->where('shop_id', $shopId)->whereNull('deleted_at')
            ->get(['id', 'name', 'emoji', 'cost', 'stock', 'supplier_id', 'master_product_id']);
        $variantsByProduct = DB::table('product_variants')->where('shop_id', $shopId)->whereNull('deleted_at')
            ->orderBy('color')->orderBy('size')
            ->get(['id', 'product_id', 'size', 'color', 'stock', 'cost'])->groupBy('product_id');

        $masterSupplier = DB::table('products')
            ->whereIn('id', $products->pluck('master_product_id')->filter()->unique())
            ->pluck('supplier_id', 'id');

        // lots, pooled across the stock pool, keyed by article group (master id, or the article's own id) and size
        $lotsByGroupSize = [];
        if ($lotsOn) {
            $groupOfProduct = DB::table('products')->whereIn('shop_id', $memberIds)->whereNull('deleted_at')
                ->get(['id', 'master_product_id'])->mapWithKeys(fn ($p) => [$p->id => $p->master_product_id ?? $p->id]);
            $lots = DB::table('product_sources')->whereIn('shop_id', $memberIds)->whereNull('deleted_at')->where('stock', '>', 0)
                ->get(['product_id', 'product_variant_id', 'supplier_id', 'stock', 'cost']);
            $lotVariants = DB::table('product_variants')->whereIn('id', $lots->pluck('product_variant_id')->filter()->unique())
                ->get(['id', 'size', 'color'])->keyBy('id');
            foreach ($lots as $lot) {
                $group = $groupOfProduct[$lot->product_id] ?? null;
                if ($group === null) {
                    continue;
                }
                $key = $lot->product_variant_id ? self::sizeKey($lotVariants[$lot->product_variant_id]->size ?? null, $lotVariants[$lot->product_variant_id]->color ?? null) : 'plain';
                $lotsByGroupSize[$group][$key][] = $lot;
            }
        }

        $out = collect();
        foreach ($products as $p) {
            $group = $p->master_product_id ?? $p->id;
            $articleSupplier = $p->master_product_id ? ($masterSupplier[$p->master_product_id] ?? $p->supplier_id) : $p->supplier_id;
            $variants = $variantsByProduct->get($p->id, collect());

            // a variant article is its sizes; a plain article is a single "size"
            $sizeRows = $variants->isNotEmpty()
                ? $variants->map(fn ($v) => ['key' => self::sizeKey($v->size, $v->color), 'label' => trim(implode(', ', array_filter([$v->size, $v->color]))) ?: '—', 'stock' => (float) $v->stock, 'mirror' => (float) ($v->cost ?? $p->cost)])->all()
                : [['key' => 'plain', 'label' => '', 'stock' => (float) $p->stock, 'mirror' => (float) $p->cost]];

            $sizes = [];
            $parts = [];
            foreach ($sizeRows as $s) {
                $lotsHere = $lotsOn ? ($lotsByGroupSize[$group][$s['key']] ?? []) : [];
                $lotQty = array_sum(array_map(fn ($l) => (float) $l->stock, $lotsHere));
                $coveredQty = 0.0;
                $value = 0.0;

                if ($s['stock'] > 0 && $lotQty > 0) {
                    $scale = $lotQty > $s['stock'] ? $s['stock'] / $lotQty : 1.0;
                    $bySupplier = [];
                    foreach ($lotsHere as $l) {
                        $sid = $l->supplier_id ? (int) $l->supplier_id : null;
                        $bySupplier[$sid]['qty'] = ($bySupplier[$sid]['qty'] ?? 0.0) + (float) $l->stock * $scale;
                        $bySupplier[$sid]['value'] = ($bySupplier[$sid]['value'] ?? 0.0) + (float) $l->stock * (float) $l->cost * $scale;
                    }
                    foreach ($bySupplier as $sid => $b) {
                        $parts[] = ['supplier_id' => $sid ?: null, 'via' => 'lot', 'qty' => $b['qty'], 'value' => $b['value']];
                        $coveredQty += $b['qty'];
                        $value += $b['value'];
                    }
                }

                $restQty = $s['stock'] - $coveredQty;            // may be negative stock: valued like it always was
                $restValue = $restQty * $s['mirror'];
                if (abs($restQty) > 1e-9 || abs($restValue) > 1e-9) {
                    $parts[] = ['supplier_id' => $articleSupplier ? (int) $articleSupplier : null, 'via' => 'article', 'qty' => $restQty, 'value' => $restValue];
                }
                $value += $restValue;

                $sizes[] = ['label' => $s['label'], 'qty' => $s['stock'], 'unit_cost' => $s['stock'] != 0 ? $value / $s['stock'] : $s['mirror'], 'value' => $value];
            }

            $out->push([
                'product_id' => (int) $p->id,
                'name' => $p->name,
                'emoji' => $p->emoji,
                'has_variants' => $variants->isNotEmpty(),
                'qty' => array_sum(array_column($sizes, 'qty')),
                'value' => array_sum(array_column($sizes, 'value')),
                'sizes' => $sizes,
                'parts' => self::mergeParts($parts),
            ]);
        }

        return $out;
    }

    private static function sizeKey(?string $size, ?string $color): string
    {
        return mb_strtolower(trim((string) $size)).'|'.mb_strtolower(trim((string) $color));
    }

    /** one part per (supplier, via) for the article, however many sizes fed it */
    private static function mergeParts(array $parts): array
    {
        $merged = [];
        foreach ($parts as $p) {
            $k = ($p['supplier_id'] ?? 'x').'|'.$p['via'];
            $merged[$k] ??= ['supplier_id' => $p['supplier_id'], 'via' => $p['via'], 'qty' => 0.0, 'value' => 0.0];
            $merged[$k]['qty'] += $p['qty'];
            $merged[$k]['value'] += $p['value'];
        }

        return array_values($merged);
    }
}
