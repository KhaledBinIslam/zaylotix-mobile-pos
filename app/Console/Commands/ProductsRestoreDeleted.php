<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ArticleVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * There is no "restore a deleted product" screen, and the model's own
 * restore() brings back EVERY trashed size of the product — including the
 * ones the owner removed on purpose weeks earlier. This brings back only the
 * sizes that went down WITH the product (the same delete timestamp), hands
 * each a fresh ZV{id} barcode when the product is a plain one, and leaves
 * every other trashed size exactly as it was.
 *
 * Dry run by default; nothing is written without --apply.
 *
 * Real incident, SEAMAN SHOE STORE, 2026-11-25 14:04: staff deleted the
 * "Boiler Suit with Radium" article by mistake — 27 sizes, 450 pcs.
 */
class ProductsRestoreDeleted extends Command
{
    protected $signature = 'products:restore {product : product id} {--deleted-at= : restore only sizes deleted at this exact time (default: the product\'s own delete time)} {--apply : really write (default is a dry run)}';

    protected $description = 'Restore a deleted product with only the sizes that were deleted together with it (dry run unless --apply).';

    public function handle(): int
    {
        $product = Product::withoutGlobalScopes()->onlyTrashed()->find((int) $this->argument('product'));
        if (! $product) {
            $this->error('No deleted product with that id.');

            return self::FAILURE;
        }
        if ($product->master_product_id !== null || Product::withoutGlobalScopes()->withTrashed()->where('master_product_id', $product->id)->exists()) {
            $this->error('This product is linked to branch copies (pooled catalogue). Restore it through the owner shop instead — this command only handles a stand-alone product.');

            return self::FAILURE;
        }

        $at = $this->option('deleted-at') ?: $product->deleted_at->format('Y-m-d H:i:s');
        $together = ProductVariant::withoutGlobalScopes()->onlyTrashed()->where('product_id', $product->id)->where('deleted_at', $at)->get();
        $others = ProductVariant::withoutGlobalScopes()->onlyTrashed()->where('product_id', $product->id)->where('deleted_at', '!=', $at)->get();
        // a deleted article has lost its barcode too, so isArticle() cannot tell — its name ("B-20069") does
        $isArticle = ArticleVariant::isArticle($product) || preg_match('/^[A-Z]{1,6}-\d{3,}$/i', trim((string) $product->name)) === 1;

        $this->line("Product #{$product->id} \"{$product->name}\" (shop {$product->shop_id}), deleted {$at}");
        $this->line('  sizes deleted together with it: '.$together->count().' — '.(float) $together->sum('stock').' pcs (product stock figure: '.(float) $product->stock.')');
        $this->line('  other trashed sizes that STAY deleted: '.$others->count());
        if ((float) $together->sum('stock') !== (float) $product->stock) {
            $this->warn('  note: the sizes\' stock does not equal the product\'s stock figure — check which sizes belong before applying.');
        }

        // a plain product's barcode is ZV{id}; an article's is derived from its colours and rebuilt by articles:regenerate-barcodes
        $mint = [];
        foreach ($together as $v) {
            if ($isArticle) {
                continue;
            }
            $zv = 'ZV'.$v->id;
            $taken = ProductVariant::withoutGlobalScopes()->withTrashed()->where('shop_id', $v->shop_id)->where('barcode', $zv)->where('id', '!=', $v->id)->exists();
            $taken ? $this->warn("  {$zv} is already used by another row — #{$v->id} comes back without a barcode") : $mint[$v->id] = $zv;
        }
        $this->line($isArticle ? '  barcodes: article — run articles:regenerate-barcodes afterwards' : '  barcodes to give back: '.count($mint).' (ZV{id})');

        if (! $this->option('apply')) {
            $this->info('DRY RUN — nothing written. Add --apply to restore.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($product, $together, $mint, $at) {
            // the model's restored() hook would bring back every trashed size; this restore wants only the chosen ones
            Product::$suppressAutoVariantRestore = true;
            try {
                $product->restore();
            } finally {
                Product::$suppressAutoVariantRestore = false;
            }
            foreach ($together as $v) {
                $v->restore();
                if (isset($mint[$v->id])) {
                    $v->forceFill(['barcode' => $mint[$v->id]])->save();
                }
            }
            ActivityLog::create([
                'shop_id' => $product->shop_id, 'user_id' => null, 'action' => 'products.restored',
                'subject_type' => Product::class, 'subject_id' => $product->id,
                'description' => "\"{$product->name}\" ফেরত আনা হয়েছে (ভুল করে মোছা হয়েছিল): {$together->count()}টা সাইজ/রং।",
                'meta' => ['deleted_at' => $at, 'variants' => $together->pluck('id')->all()],
            ]);
        });

        // withoutGlobalScopes() also drops the soft-delete scope, so "live" has to be said explicitly
        $live = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->whereNull('deleted_at')->get();
        $this->info('RESTORED. Live sizes now: '.$live->count().' — '.(float) $live->sum('stock').' pcs; product stock figure: '.(float) Product::withoutGlobalScopes()->find($product->id)->stock);

        return self::SUCCESS;
    }
}
