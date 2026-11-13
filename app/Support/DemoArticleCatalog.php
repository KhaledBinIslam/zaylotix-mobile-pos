<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Article-wise demo catalogs (Baby Park's P-10001 naming, color+size
 * variants, deterministic scannable barcodes) — shared by
 * shop:setup-demo-clothing and shops:seed-demo-articles so every demo
 * shop can carry the same rich, sellable article flow. Idempotent
 * throughout (updateOrCreate keyed the same way CatalogSync matches),
 * so a re-run tops a catalog up instead of duplicating it.
 */
class DemoArticleCatalog
{
    /** category name => [emoji, article code, serial start, sizes (empty = plain product), colors, cost, price] */
    public const CLOTHING = [
        'প্যান্ট' => ['👖', 'P', 10001, ['28', '30', '32', '34', '36'], ['কালো', 'নেভি ব্লু'], 450, 850],
        'শার্ট' => ['👔', 'S', 10001, ['M', 'L', 'XL', 'XXL'], ['সাদা', 'আকাশি'], 380, 750],
        'গেঞ্জি' => ['👕', 'T', 10001, ['M', 'L', 'XL'], ['কালো', 'সাদা'], 180, 350],
        'আন্ডারওয়্যার' => ['🩲', 'U', 10001, ['M', 'L', 'XL'], ['কালো', 'ধূসর'], 80, 180],
        'পাঞ্জাবি' => ['🥻', 'J', 10001, ['M', 'L', 'XL', 'XXL'], ['সাদা', 'মেরুন'], 650, 1250],
        'পায়জামা' => ['👘', 'Y', 10001, ['38', '40', '42', '44'], ['সাদা', 'ক্রিম'], 280, 550],
        'মেয়েদের জামা' => ['👗', 'M', 10001, ['32', '34', '36', '38'], ['গোলাপি', 'লাল'], 420, 950],
        'থ্রি-পিস' => ['🧕', 'R', 10001, ['ফ্রি সাইজ'], ['নীল', 'সবুজ', 'মেরুন'], 900, 1850],
        'কাপড় (পিস)' => ['🧵', 'K', 10001, [], [], 220, 420],
    ];

    public const FOOTWEAR = [
        'জেন্টস জুতা' => ['👞', 'GS', 10001, ['39', '40', '41', '42', '43', '44'], ['কালো', 'ব্রাউন'], 850, 1650],
        'লেডিস জুতা' => ['👠', 'LS', 10001, ['35', '36', '37', '38', '39', '40'], ['কালো', 'গোলাপি', 'মেরুন'], 550, 1150],
        'বাচ্চাদের জুতা' => ['👟', 'KD', 10001, ['25', '26', '27', '28', '29', '30', '31', '32'], ['নীল', 'লাল'], 320, 650],
        'স্যান্ডেল' => ['🩴', 'SN', 10001, ['38', '39', '40', '41', '42', '43'], ['কালো', 'ধূসর'], 280, 550],
        'স্পোর্টস কেডস' => ['⚽', 'SP', 10001, ['39', '40', '41', '42', '43', '44'], ['সাদা', 'কালো'], 750, 1450],
    ];

    private const COLOR_CODES = [
        'কালো' => 'BK', 'সাদা' => 'WT', 'নেভি ব্লু' => 'NV', 'আকাশি' => 'SKY',
        'ধূসর' => 'GR', 'মেরুন' => 'MR', 'ক্রিম' => 'CRM', 'গোলাপি' => 'PK',
        'লাল' => 'RD', 'নীল' => 'BL', 'সবুজ' => 'GRN', 'ব্রাউন' => 'BR',
    ];

    /** @param  array<string, array>  $catalog  one of the consts above */
    public static function seedInto(Shop $shop, array $catalog, string $barcodePrefix, int $articlesPerCategory, float $qty): array
    {
        $pieceUnitId = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('name', 'like', '%পিস%')->value('id');

        $productCount = 0;
        $variantCount = 0;

        foreach ($catalog as $categoryName => [$emoji, $code, $serialStart, $sizes, $colors, $cost, $price]) {
            $category = ProductCategory::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $categoryName],
                ['emoji' => $emoji, 'code' => $code, 'serial_start' => $serialStart]
            );

            for ($i = 0; $i < $articlesPerCategory; $i++) {
                $articleNo = $code.'-'.($serialStart + $i);
                $hasVariants = $sizes !== [] && $colors !== [];
                // a little price spread between articles so the demo's
                // reports/bills don't show one identical number everywhere
                $articleCost = $cost + $i * 30;
                $articlePrice = $price + $i * 50;

                $product = Product::withoutGlobalScopes()->updateOrCreate(
                    ['shop_id' => $shop->id, 'name' => $articleNo],
                    [
                        'category_id' => $category->id,
                        'unit_id' => $pieceUnitId,
                        'emoji' => $emoji,
                        'barcode' => $barcodePrefix.'-'.$articleNo,
                        'cost' => $articleCost,
                        'price' => $articlePrice,
                        'deleted_at' => null,
                    ]
                );
                $productCount++;

                if (! $hasVariants) {
                    Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock' => $qty * 2]);

                    continue;
                }

                foreach ($colors as $color) {
                    $colorCode = self::COLOR_CODES[$color] ?? Str::upper(Str::limit(Str::ascii($color), 3, ''));
                    foreach ($sizes as $size) {
                        ProductVariant::withoutGlobalScopes()->updateOrCreate(
                            ['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => $size, 'color' => $color],
                            [
                                'barcode' => $barcodePrefix.'-'.$articleNo.'-'.$colorCode.'-'.Str::upper(Str::ascii($size)),
                                'price' => $articlePrice,
                                'cost' => $articleCost,
                                'stock' => $qty,
                                'deleted_at' => null,
                            ]
                        );
                        $variantCount++;
                    }
                }

                $sum = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->id)->whereNull('deleted_at')->sum('stock');
                Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock' => $sum]);
            }
        }

        return ['products' => $productCount, 'variants' => $variantCount];
    }
}
