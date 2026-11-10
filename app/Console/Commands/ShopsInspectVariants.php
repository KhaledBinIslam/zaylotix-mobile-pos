<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Read-only debug: Liberty Bali live report — printed labels pairing a
 * product's NAME/VARIANT text with a barcode/price that belongs to a
 * DIFFERENT variant. Tinker's interactive shell can't run on this host
 * (proc_open is disabled), so this is the read-only lookup in command
 * form instead, scoped to whatever --shop/--names the report is about.
 */
class ShopsInspectVariants extends Command
{
    protected $signature = 'shops:inspect-variants {--shop=} {--names=}';

    protected $description = 'Dumps a shop\'s named products with every variant\'s size/color/barcode/scan_code, to check for a mismatch between a variant\'s own label fields and its own barcode.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        $names = array_filter(array_map('trim', explode(',', (string) $this->option('names'))));

        if (! $shopId || ! $names) {
            $this->error('--shop=<id> --names=L-10003,L-10004 দিতে হবে।');

            return self::FAILURE;
        }

        $products = Product::withoutGlobalScopes()->where('shop_id', $shopId)->whereIn('name', $names)->with('variants')->get();

        foreach ($products as $p) {
            $this->line("{$p->name} id={$p->id} product_barcode={$p->barcode}");
            foreach ($p->variants as $v) {
                $this->line("  variant id={$v->id} size={$v->size} color={$v->color} barcode={$v->barcode} scan_code={$v->scan_code} price={$v->price} deleted=" . ($v->deleted_at ? 'yes' : 'no'));
            }
        }

        return self::SUCCESS;
    }
}
