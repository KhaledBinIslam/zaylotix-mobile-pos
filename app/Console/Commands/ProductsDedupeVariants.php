<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Removes a size/colour that is listed more than once on the same
 * product.
 *
 * A branch gets a copy of every size from its main shop (CatalogSync).
 * Deleting a size at the main shop did not delete the branch's copy, so
 * when that size was entered again the branch was handed a SECOND row for
 * it. Baby Park's branch ended up listing eight sizes twice: the POS
 * showed each one in duplicate, and since products.stock is the live sum
 * of its variants, eleven units existed that were never on a shelf.
 *
 * ProductVariant::deleteBranchCopies() stops new ones being made; this
 * clears what is already there.
 *
 * The row that is KEPT is the one carrying a barcode — that is the one
 * whose sticker can be scanned and whose label prints, and it is the one
 * the main shop's own live row corresponds to. Where several or none
 * carry one, the oldest wins, so this is deterministic and re-runnable.
 *
 * products.stock is deliberately NOT rewritten: a duplicate's units were
 * never real, so the product's own figure is usually already right (Baby
 * Park's branch read 32 against a variant sum of 43, and dropping the
 * duplicates brings the sum to exactly 32). Where it does not line up,
 * the command says so rather than writing a number over live stock.
 */
class ProductsDedupeVariants extends Command
{
    protected $signature = 'products:dedupe-variants {--shop=} {--name= : shops whose name contains this, instead of an id} {--dry-run}';

    protected $description = 'Removes duplicate size/colour variants from a product, keeping the one with a barcode. --dry-run previews only.';

    public function handle(): int
    {
        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $name) => $q->where('name', 'like', '%'.$name.'%'))
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
            $removed = 0;
            $drifted = [];

            foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->get() as $product) {
                $live = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->id)->whereNull('deleted_at')
                    ->orderBy('id')->get();

                $groups = $live->groupBy(fn (ProductVariant $v) => ($v->size ?? '').'|'.($v->color ?? ''))
                    ->filter(fn ($rows) => $rows->count() > 1);

                foreach ($groups as $rows) {
                    $keep = $rows->first(fn (ProductVariant $v) => filled($v->barcode)) ?? $rows->first();

                    foreach ($rows as $row) {
                        if ($row->id === $keep->id) {
                            continue;
                        }

                        $this->line("  \"{$product->name_en} / {$row->label()}\" — বাড়তি রো id {$row->id} (স্টক {$row->stock}) "
                            .($isDryRun ? 'বাদ যেত' : 'বাদ দেওয়া হলো').", রাখা হলো id {$keep->id}"
                            .(filled($keep->barcode) ? " ({$keep->barcode})" : ' (বারকোড নেই)').'।');

                        if (! $isDryRun) {
                            ProductVariant::withoutGlobalScopes()->whereKey($row->id)
                                ->update(['deleted_at' => now(), 'barcode' => null]);
                        }
                        $removed++;
                    }
                }

                // after the duplicates go, does the product's own figure
                // agree with its sizes again? Reported, never rewritten —
                // this is live stock, and a mismatch here means something
                // else is wrong that a silent UPDATE would only hide.
                $sumAfter = $live->reject(function (ProductVariant $v) use ($live) {
                    $group = $live->filter(fn (ProductVariant $o) => ($o->size ?? '') === ($v->size ?? '') && ($o->color ?? '') === ($v->color ?? ''));
                    $keep = $group->first(fn (ProductVariant $o) => filled($o->barcode)) ?? $group->first();

                    return $group->count() > 1 && $v->id !== $keep->id;
                })->sum('stock');

                if ($groups->isNotEmpty() && (float) $product->stock !== (float) $sumAfter) {
                    $drifted[] = "{$product->name_en}: products.stock {$product->stock}, সাইজগুলোর যোগফল {$sumAfter}";
                }
            }

            $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$removed}টা বাড়তি রো।");

            foreach ($drifted as $line) {
                $this->warn("  ⚠ {$line} — মিলছে না, হাতে দেখতে হবে।");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
