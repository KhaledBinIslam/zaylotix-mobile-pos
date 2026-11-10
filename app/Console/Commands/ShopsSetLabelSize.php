<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Liberty Bali live: the physical sticker is 38x25mm, but the printer's
 * own "RT Label"/Set Printer tool enforces a hard minimum Label Height of
 * 30mm -- it will not go below 30, no matter what the real die-cut sticker
 * measures. Every print job the printer actually feeds 30mm per label
 * regardless of what we ask for, so as long as OUR page height stayed at
 * 25mm the two pitches drifted apart print after print (content for label
 * N+1 always starting ~5mm into what the printer thinks is still label N's
 * blank tail), landing a label's own name/header on one physical sticker
 * and its barcode/price on the next. Setting our own configured height to
 * match the printer's forced 30mm makes our page pitch equal the
 * printer's real feed pitch, so every page boundary lines up with a real
 * die-cut edge again -- the extra ~5mm of each page is simply blank space
 * sitting on the gap/liner between real stickers, never printed-on
 * content, since card content stays vertically centered in whatever
 * height it's given.
 */
class ShopsSetLabelSize extends Command
{
    protected $signature = 'shops:set-label-size {--shop=} {--width=} {--height=}';

    protected $description = 'Sets a shop\'s label_settings to a custom width/height in mm, leaving every other label setting untouched.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        $width = $this->option('width');
        $height = $this->option('height');

        if (! $shopId || ! is_numeric($width) || ! is_numeric($height)) {
            $this->error('--shop=<id>, --width=<mm>, --height=<mm> তিনটাই সংখ্যা দিয়ে দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ id {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $settings = $shop->label_settings ?? [];
        $before = ($settings['size'] ?? '?') === 'custom'
            ? ($settings['custom_width_mm'] ?? '?') . 'x' . ($settings['custom_height_mm'] ?? '?')
            : ($settings['size'] ?? '(none)');

        $settings['size'] = 'custom';
        $settings['custom_width_mm'] = (float) $width;
        $settings['custom_height_mm'] = (float) $height;
        $shop->update(['label_settings' => $settings]);

        $this->info("শপ {$shop->id} — label size: {$before} → {$width}x{$height}mm (custom)");

        return self::SUCCESS;
    }
}
