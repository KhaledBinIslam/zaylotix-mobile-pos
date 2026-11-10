<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Liberty Bali: cloned from Baby Park, including label_settings.print_mode
 * = "thermal" — Baby Park prints labels via a Bluetooth thermal printer
 * and the Android "Printer helper" companion app, with dot-exact sizing
 * calibrated for that specific printer (XP-365B). Liberty Bali prints from
 * a LAPTOP to a USB-connected Rongta RP400H instead — a completely
 * different mechanism (plain browser print to a Windows-driver printer),
 * and "thermal" mode's dot-exact math is calibrated for the wrong
 * hardware. "classic" mode is the byte-identical pre-Phase-41 behaviour:
 * plain CSS @page sizing, which is what a normal Windows printer driver
 * expects. The label SIZE itself (e.g. 38x25mm) is a separate key, left
 * untouched.
 */
class ShopsSetLabelPrintMode extends Command
{
    protected $signature = 'shops:set-label-print-mode {--shop=} {--mode=}';

    protected $description = 'Sets only the print_mode key inside a shop\'s label_settings, leaving every other label setting (size, template, etc.) untouched.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        $mode = trim((string) $this->option('mode'));

        if (! $shopId || ! in_array($mode, ['classic', 'thermal'], true)) {
            $this->error('--shop=<id> আর --mode=classic|thermal দুটোই দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ id {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $settings = $shop->label_settings ?? [];
        $before = $settings['print_mode'] ?? '(none)';
        $settings['print_mode'] = $mode;
        $shop->update(['label_settings' => $settings]);

        $this->info("শপ {$shop->id} — label print_mode: {$before} → {$mode}");

        return self::SUCCESS;
    }
}
