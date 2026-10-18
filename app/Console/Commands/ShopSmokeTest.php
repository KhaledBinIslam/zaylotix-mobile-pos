<?php

namespace App\Console\Commands;

use App\Http\Controllers\App\PosController;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Support\FeatureRecommendations;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Provisions a throwaway shop for a given business type (or every type with
 * --all), exercises the path most likely to break per shop type — category/
 * unit seeding, a product create, and a real POS checkout — then always
 * rolls back, never leaving any trace in real data. Built alongside the
 * Phase 0 provisioning-reliability fixes (see docs/FIX_PLAN.md) as the
 * repeatable regression check for "does a brand-new shop of this type
 * actually work end to end."
 *
 * Deliberately scoped to provisioning + checkout — the two areas Phase 0's
 * research found real risk in. Purchase/supplier and return flows were
 * already confirmed correct by that research and aren't re-exercised here
 * to keep this command simple; extend it if a future audit finds a
 * provisioning-time risk in those flows too.
 */
class ShopSmokeTest extends Command
{
    protected $signature = 'shop:smoke-test {type?} {--all}';

    protected $description = 'Provision a throwaway shop of the given business type (or every type with --all), run it through a product + checkout, then roll back.';

    public function handle(): int
    {
        $slugs = $this->option('all')
            ? array_keys(config('business_types'))
            : array_filter([$this->argument('type')]);

        if (empty($slugs)) {
            $this->error('একটা business type slug দিন, বা --all দিয়ে সবগুলো চালান। যেমন: php artisan shop:smoke-test grocery');

            return self::FAILURE;
        }

        $allOk = true;
        foreach ($slugs as $slug) {
            $allOk = $this->runOne($slug) && $allOk;
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function runOne(string $slug): bool
    {
        $businessType = BusinessType::where('slug', $slug)->first();
        if (! $businessType) {
            $this->error("[{$slug}] এই slug-এর কোনো BusinessType DB-তে নেই — skip করা হলো।");

            return false;
        }

        DB::beginTransaction();
        $ok = true;
        $message = 'OK';

        try {
            $shop = ShopProvisioner::provisionKeepingTenancy(
                shopAttrs: [
                    'business_type_id' => $businessType->id,
                    'name' => "Smoke test - {$slug}",
                    'name_en' => "Smoke test - {$slug}",
                    'phone' => 'SMOKE-'.$slug.'-'.uniqid(),
                    'owner_name' => 'Smoke Test',
                    'sales_mode' => 'both',
                    'lang' => 'bn',
                    'plan' => 'trial',
                    'account_type' => 'internal',
                    'status' => 'active',
                    'subscription_start' => now()->toDateString(),
                    'subscription_expiry' => now()->addDays(1)->toDateString(),
                ],
                ownerName: 'Smoke Test',
                ownerPhone: null,
                ownerEmail: 'smoke-'.$slug.'-'.uniqid().'@smoketest.local',
                ownerPassword: 'password',
                featureKeys: FeatureRecommendations::forBusinessType($slug),
            );

            $categoryCount = ProductCategory::where('shop_id', $shop->id)->count();
            $unitCount = Unit::where('shop_id', $shop->id)->count();
            if ($categoryCount === 0 || $unitCount === 0) {
                throw new RuntimeException("provisioning left {$categoryCount} categories / {$unitCount} units — expected at least 1 of each");
            }

            $category = ProductCategory::where('shop_id', $shop->id)->first();
            $unit = Unit::where('shop_id', $shop->id)->first();

            $product = Product::create([
                'shop_id' => $shop->id,
                'name' => 'Smoke test product',
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'price' => 100,
                'cost' => 60,
                'stock' => 10,
            ]);

            $sale = PosController::performCheckout(
                data: [
                    'items' => [
                        ['product_id' => $product->id, 'qty' => 1],
                    ],
                    'payments' => [
                        ['method' => 'cash', 'amount' => 100],
                    ],
                ],
                shopId: $shop->id,
                userId: null,
            );

            if ((float) $sale->total !== 100.0) {
                throw new RuntimeException("checkout total came back as {$sale->total}, expected 100");
            }

            $remainingStock = (float) $product->fresh()->stock;
            if ($remainingStock !== 9.0) {
                throw new RuntimeException("stock after checkout is {$remainingStock}, expected 9");
            }
        } catch (Throwable $e) {
            $ok = false;
            $message = $e->getMessage();
        } finally {
            Tenancy::clear();
            DB::rollBack();
        }

        if ($ok) {
            $this->info("[{$slug}] OK");
        } else {
            $this->error("[{$slug}] FAIL - {$message}");
        }

        return $ok;
    }
}
