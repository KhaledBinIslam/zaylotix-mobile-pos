<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CatalogSync;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Khaled: "amar ambassador eta dekhai sell ante pare" — his sales
 * ambassador needs a shop to demo that looks like a real, stocked
 * business, not a generic sample. This creates a brand-new, fully
 * independent shop (no parent_shop_id — never a real branch of the
 * source, so it can never join a shared stock pool with it) seeded with
 * a verbatim copy of a real paying shop's product catalog: categories,
 * units, products, variants, price tiers, suppliers, and source lines,
 * every one a fresh row under the new shop_id.
 *
 * Deliberately NOT copied, even though the catalog copy is verbatim:
 * supplier payable balances (real money owed, reset to 0 — a prospect
 * has no reason to see another business's real debts), customers, sales
 * history, and activity logs. None of that is "what products does this
 * business carry," which is the one thing this command exists to show
 * off, and copying it would mean handing a stranger a real shop's real
 * revenue/customer data for a sales pitch nobody asked for.
 *
 * One-way and read-only against the source shop — never writes to it,
 * never sets parent_shop_id, and strips branch-only features
 * (shared_stock_pool/shared_catalog/shared_promotions/
 * transfer_confirmation) before granting the rest, so nothing done in
 * the demo afterwards (a demo sale, a stock correction) can ever reach
 * the real shop's live data.
 */
class CloneShopForDemo extends Command
{
    protected $signature = 'demo:clone-shop
        {source_shop_id : The real shop to copy the catalog from}
        {name : New demo shop\'s name}
        {owner_email : Login email for the demo account}
        {owner_password : Login password for the demo account}
        {--name_en= : English name (defaults to the Bangla/given name)}
        {--days=90 : Days until the demo shop\'s subscription expiry}';

    protected $description = "Create a standalone demo shop seeded with a verbatim copy of a real shop's product catalog, for ambassador sales demos.";

    public function handle(): int
    {
        $source = Shop::withoutGlobalScopes()->find($this->argument('source_shop_id'));
        if (! $source) {
            $this->error('Source shop not found.');

            return self::FAILURE;
        }

        $email = $this->argument('owner_email');
        if (User::where('email', $email)->exists()) {
            $this->error('A user with that email already exists.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $nameEn = $this->option('name_en') ?: $name;

        $featureKeys = $source->featureKeys()
            ->reject(fn ($k) => in_array($k, ['shared_stock_pool', 'shared_catalog', 'shared_promotions', 'transfer_confirmation'], true))
            ->values()->all();

        $demo = DB::transaction(function () use ($source, $name, $nameEn, $email, $featureKeys) {
            $shop = ShopProvisioner::provisionKeepingTenancy([
                'business_type_id' => $source->business_type_id,
                'name' => $name,
                'name_en' => $nameEn,
                'phone' => $this->freePhone(),
                'area' => $source->area,
                'owner_name' => $name.' Owner',
                'sales_mode' => $source->sales_mode,
                'lang' => $source->lang,
                'plan' => 'demo',
                'account_type' => 'demo',
                'status' => 'active',
                'staff_limit' => 10,
                'subscription_start' => now()->toDateString(),
                'subscription_expiry' => now()->addDays((int) $this->option('days'))->toDateString(),
            ], $name.' Owner', null, $email, $this->argument('owner_password'), $featureKeys);

            Tenancy::clear();

            CatalogSync::syncToBranch($source, $shop);

            $productMap = $this->mapProducts($source, $shop);
            $variantMap = $this->mapVariants($source, $shop, $productMap);

            // CatalogSync starts every row at 0 (or at the source's pooled
            // figure, if it happens to have shared_stock_pool — not the
            // fidelity Khaled asked for either way): this demo's whole
            // point is looking like a real, stocked shop, so every mapped
            // row is set to literally the source's own current count.
            foreach ($productMap as $sourceId => $newId) {
                $stock = Product::withoutGlobalScopes()->whereKey($sourceId)->value('stock');
                Product::withoutGlobalScopes()->whereKey($newId)->update(['stock' => $stock]);
            }
            foreach ($variantMap as $sourceId => $newId) {
                $stock = ProductVariant::withoutGlobalScopes()->whereKey($sourceId)->value('stock');
                ProductVariant::withoutGlobalScopes()->whereKey($newId)->update(['stock' => $stock]);
            }

            if ($source->hasFeature('suppliers')) {
                $this->cloneSuppliersAndSources($source, $shop, $productMap, $variantMap);
            }

            return $shop;
        });

        $this->info("Demo shop created: #{$demo->id} \"{$demo->name}\"");
        $this->info("Login email: {$email}");

        return self::SUCCESS;
    }

    private function freePhone(): string
    {
        do {
            $phone = '01799'.random_int(100000, 999999);
        } while (Shop::withoutGlobalScopes()->where('phone', $phone)->exists());

        return $phone;
    }

    private function cloneSuppliersAndSources(Shop $source, Shop $demo, array $productMap, array $variantMap): void
    {
        $supplierMap = [];
        foreach (Supplier::withoutGlobalScopes()->where('shop_id', $source->id)->get() as $s) {
            $new = Supplier::create([
                'shop_id' => $demo->id,
                'name' => $s->name,
                'phone' => $s->phone,
                'address' => $s->address,
                'short_code' => $s->short_code,
                'payable' => 0, // catalog fidelity, not a copy of real money owed
            ]);
            $supplierMap[$s->id] = $new->id;
        }

        if (! $source->hasFeature('source_lines')) {
            return;
        }

        foreach (ProductSource::withoutGlobalScopes()->where('shop_id', $source->id)->get() as $line) {
            $newProductId = $line->product_id ? ($productMap[$line->product_id] ?? null) : null;
            $newVariantId = $line->product_variant_id ? ($variantMap[$line->product_variant_id] ?? null) : null;

            // a line whose product/variant didn't come across intact is
            // skipped rather than guessed at — better a missing lot than
            // one attached to the wrong product
            if ($line->product_id && ! $newProductId) {
                continue;
            }
            if ($line->product_variant_id && ! $newVariantId) {
                continue;
            }

            ProductSource::create([
                'shop_id' => $demo->id,
                'product_id' => $newProductId,
                'product_variant_id' => $newVariantId,
                'supplier_id' => $line->supplier_id ? ($supplierMap[$line->supplier_id] ?? null) : null,
                'cost' => $line->cost,
                'price' => $line->price,
                'stock' => $line->stock,
                // (shop_id, barcode) uniqueness is per-shop, so the source's
                // own lot barcode is free to reuse verbatim here
                'barcode' => $line->barcode,
            ]);
        }
    }

    /** @return array<int,int> source product_id => demo shop's own product_id */
    private function mapProducts(Shop $source, Shop $demo): array
    {
        $byBarcode = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->whereNotNull('barcode')->get()->keyBy('barcode');
        $byName = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->get()->keyBy('name');

        $map = [];
        foreach (Product::withoutGlobalScopes()->where('shop_id', $source->id)->whereNull('deleted_at')->get() as $p) {
            $match = (filled($p->barcode) ? $byBarcode->get($p->barcode) : null) ?? $byName->get($p->name);
            if ($match) {
                $map[$p->id] = $match->id;
            }
        }

        return $map;
    }

    /** @return array<int,int> source variant_id => demo shop's own variant_id */
    private function mapVariants(Shop $source, Shop $demo, array $productMap): array
    {
        $byBarcode = ProductVariant::withoutGlobalScopes()->where('shop_id', $demo->id)->whereNotNull('barcode')->get()->keyBy('barcode');
        $bySizeColor = ProductVariant::withoutGlobalScopes()->where('shop_id', $demo->id)->get()
            ->keyBy(fn ($v) => $v->product_id.'|'.$v->size.'|'.$v->color);

        $map = [];
        foreach (ProductVariant::withoutGlobalScopes()->where('shop_id', $source->id)->whereNull('deleted_at')->get() as $v) {
            $newProductId = $productMap[$v->product_id] ?? null;
            if (! $newProductId) {
                continue;
            }
            $match = (filled($v->barcode) ? $byBarcode->get($v->barcode) : null)
                ?? $bySizeColor->get($newProductId.'|'.$v->size.'|'.$v->color);
            if ($match) {
                $map[$v->id] = $match->id;
            }
        }

        return $map;
    }
}
