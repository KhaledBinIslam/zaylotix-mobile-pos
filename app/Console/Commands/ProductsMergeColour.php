<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * The same colour recorded under two names.
 *
 * Baby Park's branch had B-20009 listed twice over: sixteen sizes under
 * BROWN and PINK, and eight more under বাদামী and গোলাপি with the same
 * counts. products.stock read 25 while the sizes summed to 41 — the
 * extra sixteen were the stale Bengali rows, left behind when the
 * colours were renamed into English. Not stock, just an old spelling,
 * and a branch that disagreed with its main shop over what was on the
 * shelf.
 *
 * Two outcomes per size:
 *   - the new name already has that size → the old row is a duplicate
 *     and goes, its barcode released like any other removal
 *   - it does not → the old row is simply renamed, keeping its stock,
 *     because it is the only record of those pairs
 *
 * products.stock is never rewritten. It is the live sum of the
 * variants, and where a duplicate is dropped it was already right (25,
 * not 41). Where the two disagree afterwards the command says so rather
 * than writing a number over live stock.
 */
class ProductsMergeColour extends Command
{
    protected $signature = 'products:merge-colour {--shop=} {--name= : shops whose name contains this, instead of an id} {--from= : the colour name to retire} {--to= : the colour name to keep} {--dry-run}';

    protected $description = 'Merges one colour name into another on every product of a shop. --dry-run previews only.';

    public function handle(): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        if ($from === '' || $to === '' || $from === $to) {
            $this->error('--from=<পুরোনো রং> আর --to=<নতুন রং> দিতে হবে, এবং দুটো আলাদা হতে হবে।');

            return self::FAILURE;
        }

        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $n) => $q->where('name', 'like', '%'.$n.'%'))
            ->orderBy('id')->get();

        if (! $this->option('shop') && ! $this->option('name')) {
            $this->error('--shop=<id> অথবা --name=<দোকানের নাম> দিতে হবে।');

            return self::FAILURE;
        }
        if ($shops->isEmpty()) {
            $this->error('কোনো শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $shops = ArticlesSettingsDoctor::withBranchesOf($shops);
        $isDryRun = (bool) $this->option('dry-run');

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");
            $merged = 0;
            $renamed = 0;

            foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->get() as $product) {
                $live = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->id)->whereNull('deleted_at')->orderBy('id')->get();

                $olds = $live->filter(fn (ProductVariant $v) => (string) $v->color === $from);
                if ($olds->isEmpty()) {
                    continue;
                }

                foreach ($olds as $old) {
                    $twin = $live->first(fn (ProductVariant $v) => (string) $v->color === $to && (string) $v->size === (string) $old->size);

                    if ($twin) {
                        $note = (float) $twin->stock === (float) $old->stock
                            ? 'স্টক একই'
                            : "<fg=yellow>স্টক আলাদা — রাখা হচ্ছে {$twin->stock}, বাদ যাচ্ছে {$old->stock}</>";
                        $this->line("  \"{$product->name_en}\" {$old->size}/{$from} (id {$old->id}) — {$old->size}/{$to}-এ আছে, তাই "
                            .($isDryRun ? 'বাদ যেত' : 'বাদ দেওয়া হলো').", {$note}।");
                        if (! $isDryRun) {
                            ProductVariant::withoutGlobalScopes()->whereKey($old->id)
                                ->update(['deleted_at' => now(), 'barcode' => null]);
                        }
                        $merged++;

                        continue;
                    }

                    $this->line("  \"{$product->name_en}\" {$old->size}/{$from} (id {$old->id}, স্টক {$old->stock}) — {$to} নামে "
                        .($isDryRun ? 'বদলাতো' : 'বদলানো হলো').'।');
                    if (! $isDryRun) {
                        // barcode released: it carries the old colour's
                        // code, and articles:regenerate-barcodes mints
                        // the new one from the colour it now has
                        ProductVariant::withoutGlobalScopes()->whereKey($old->id)
                            ->update(['color' => $to, 'barcode' => null]);
                    }
                    $renamed++;
                }

                if (! $isDryRun) {
                    $sum = (float) ProductVariant::withoutGlobalScopes()
                        ->where('product_id', $product->id)->whereNull('deleted_at')->sum('stock');
                    if ((float) $product->stock !== $sum) {
                        $this->warn("  ⚠ \"{$product->name_en}\" — products.stock {$product->stock}, সাইজগুলোর যোগফল {$sum}। মিলছে না, হাতে দেখতে হবে।");
                    }
                }
            }

            $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$merged}টা বাদ, {$renamed}টা নাম বদল।");
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
