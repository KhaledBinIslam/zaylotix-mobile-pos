<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Prints what the article settings ACTUALLY hold, per shop, read-only.
 *
 * "Is the category ticked?" turned into several rounds of guessing: the
 * settings page shows the checkbox state of the FORM, which survives a
 * failed save (an overlapping size range refuses the whole patch), so a
 * tick on screen does not prove a tick in the database. This prints the
 * stored side of it — and, because these settings resolve through
 * Shop::articleSettingsShop(), which shop is actually answering for the
 * business.
 *
 * Read-only on purpose: it is for running on production mid-support.
 */
class ArticlesSettingsDoctor extends Command
{
    protected $signature = 'articles:settings-doctor {--shop= : only this shop id} {--name= : only shops whose name contains this}';

    protected $description = 'Shows the saved size-group / cost-code / colour-code settings per shop, and which shop answers for each.';

    public function handle(): int
    {
        // --name because on production nobody remembers the shop id, and
        // dumping every tenant to find one shop is unusable mid-support
        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $name) => $q->where('name', 'like', '%'.$name.'%'))
            ->orderBy('id')->get();

        if ($shops->isEmpty()) {
            $this->error('কোনো শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            $settingsShop = $shop->articleSettingsShop();
            $viaParent = $settingsShop->id !== $shop->id;

            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");
            $this->line('  settings come from: '.($viaParent
                ? "shop {$settingsShop->id} ({$settingsShop->name}) — this is a BRANCH"
                : 'itself (main shop)'));

            $codes = $shop->sizeGroupCategoryCodes();
            $this->line('  size-group categories: '.($codes ? '<fg=green>'.implode(', ', $codes).'</>' : '<fg=red>NONE — group pricing is off, falling back to name matching</>'));

            $groups = $shop->kidsShoeSizeGroups();
            $custom = $settingsShop->kids_shoe_size_groups !== null ? 'saved' : 'DEFAULTS (never saved)';
            $this->line("  size groups ({$custom}):");
            foreach ($groups as $g) {
                $this->line("    - {$g['label']}: {$g['min_size']}–{$g['max_size']}");
            }

            // every category this shop owns, and the verdict for each
            $categories = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->orderBy('name')->get();
            if ($categories->isNotEmpty()) {
                $this->line('  categories:');
                foreach ($categories as $c) {
                    $code = strtoupper(trim((string) $c->code));
                    $on = $codes !== [] ? in_array($code, $codes, true) : false;
                    $mark = $on ? '<fg=green>GROUP PRICES</>' : '<fg=yellow>one price</>';
                    $this->line("    - {$c->name} (code: ".($code ?: '—').") → {$mark}");
                }
            }

            $missing = array_keys(array_filter($shop->costCodeDigitMap(), fn ($letter) => $letter === null || $letter === ''));
            $unmapped = array_values(array_diff(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], array_keys(array_filter($shop->costCodeDigitMap()))));
            $this->line('  cost-code digits still unmapped: '.($unmapped || $missing
                ? '<fg=red>'.implode(', ', array_unique(array_merge($unmapped, $missing))).'</>'
                : '<fg=green>none</>'));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
