<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds shops that ended up with zero product categories and/or zero units
 * — the exact symptom of the ShopProvisioner / business_types.php config
 * drift bug fixed alongside this command (see docs/FIX_PLAN.md Phase 0.1/
 * 0.2) — and backfills them from that shop's business-type defaults
 * (falling back to the 'general' profile if the business type has no
 * matching config entry, or is missing entirely).
 *
 * Preview-only by default; pass --confirm to actually write. Never touches
 * a shop that already has at least one category AND at least one unit —
 * this is purely a repair for shops stuck at zero, never a "reset to
 * defaults" tool.
 */
class ShopsRepairDefaults extends Command
{
    protected $signature = 'shops:repair-defaults {--dry-run} {--confirm}';

    protected $description = 'Preview (or, with --confirm, apply) backfilling default categories/units for shops that have zero of either.';

    public function handle(): int
    {
        $shops = Shop::withoutGlobalScopes()->with('businessType')->get();

        $broken = [];
        foreach ($shops as $shop) {
            $categoryCount = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
            $unitCount = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count();

            if ($categoryCount === 0 || $unitCount === 0) {
                $broken[] = [
                    'shop' => $shop,
                    'category_count' => $categoryCount,
                    'unit_count' => $unitCount,
                ];
            }
        }

        if (empty($broken)) {
            $this->info('সব শপের already কমপক্ষে একটা category এবং একটা unit আছে — ঠিক করার কিছু নেই।');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Phone', 'Business type', 'Categories', 'Units', 'যে profile থেকে fill হবে'],
            collect($broken)->map(function ($row) {
                $shop = $row['shop'];
                $slug = $shop->businessType?->slug;
                $defaults = $slug ? config('business_types.'.$slug) : null;
                $fallbackUsed = $defaults === null;

                return [
                    $shop->id, $shop->name, $shop->phone, $slug ?? '—',
                    $row['category_count'], $row['unit_count'],
                    $fallbackUsed ? 'general (fallback)' : $slug,
                ];
            })
        );

        $isDryRun = $this->option('dry-run') || ! $this->option('confirm');
        if ($isDryRun) {
            $this->newLine();
            $this->info(count($broken).'টা শপে category/unit কম পাওয়া গেছে। এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে --confirm যোগ করে আবার চালান।');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($broken as $row) {
            $shop = $row['shop'];
            $slug = $shop->businessType?->slug;
            $defaults = ($slug ? config('business_types.'.$slug) : null) ?? config('business_types.general') ?? [];

            DB::transaction(function () use ($shop, $defaults, $row) {
                Tenancy::set($shop->id);

                if ($row['category_count'] === 0) {
                    foreach (($defaults['categories'] ?? []) as $cat) {
                        ProductCategory::create([
                            'shop_id' => $shop->id,
                            'name' => $cat['name'],
                            'name_en' => $cat['name_en'],
                            'emoji' => $cat['emoji'],
                        ]);
                    }
                }

                if ($row['unit_count'] === 0) {
                    foreach (($defaults['units'] ?? []) as $unit) {
                        Unit::create([
                            'shop_id' => $shop->id,
                            'name' => $unit['name'],
                            'name_en' => $unit['name_en'],
                            'code' => $unit['code'],
                        ]);
                    }
                }

                Tenancy::clear();
            });

            $fixed++;
        }

        $this->newLine();
        $this->info("{$fixed}টা শপ ঠিক করা হলো।");

        return self::SUCCESS;
    }
}
