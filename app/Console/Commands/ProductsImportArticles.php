<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\ArticleBarcode;
use App\Support\ArticleSerial;
use App\Support\CatalogSync;
use App\Support\ColorBarcodeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * docs/ARTICLE_SERIES_PLAN.md §5/§6 — reads the real grid-shaped "Products"
 * sheet Khaled actually filled in (NOT the Guide sheet's "one row per
 * size" format). One spreadsheet row = one article (unlike the older
 * ProductImportController's CSV, which groups several rows into one
 * product) — confirmed against the real file: each row already has its
 * own unique name_en/barcode.
 *
 * **--dry-run is the only mode that has ever been run against real data.
 * Per the ticket's own explicit rule, the real (non-dry-run) write path
 * must only ever be invoked after Khaled reviews a dry-run report and
 * says "ঠিক আছে".**
 */
class ProductsImportArticles extends Command
{
    protected $signature = 'products:import-articles {file} {--shop=} {--dry-run}';

    protected $description = 'Import shoe articles (name/barcode/color-size grid) from the grid-shaped Products sheet. --dry-run previews only.';

    private const COLOR_COLUMNS = ['BLACK', 'WHITE', 'BROWN', 'COFFE', 'SILVER', 'KHAKI', 'GOLDEN', 'MAROON', 'GREY'];

    /** header label -> canonical color name used for variants/barcodes */
    private const COLOR_LABEL_FIX = ['COFFE' => 'COFFEE'];

    /** English category label in the sheet -> existing footwear category to reuse (never creates a duplicate "LADIES" category) */
    private const CATEGORY_SYNONYMS = [
        'LADIES' => ["Women's Shoes", 'মেয়েদের জুতা'],
        'WOMEN' => ["Women's Shoes", 'মেয়েদের জুতা'],
        'MENS' => ["Men's Shoes", 'ছেলেদের জুতা'],
        'GENTS' => ["Men's Shoes", 'ছেলেদের জুতা'],
        'MEN' => ["Men's Shoes", 'ছেলেদের জুতা'],
        'KIDS' => ["Kids' Shoes", 'বাচ্চাদের জুতা'],
        'SANDAL' => ['Sandals', 'স্যান্ডেল'],
        'SANDALS' => ['Sandals', 'স্যান্ডেল'],
        'SNEAKER' => ['Sneakers', 'স্নিকার'],
        'SNEAKERS' => ['Sneakers', 'স্নিকার'],
        'SCHOOL' => ['School Shoes', 'স্কুল শু'],
    ];

    /** one letter per category code, assigned in the order first encountered — overridden by an existing category's own `code` if it already has one */
    private const CATEGORY_CODE_FALLBACK_LETTERS = ['L', 'M', 'K', 'S', 'N', 'O', 'P', 'Q', 'T', 'U'];

    /**
     * Real bug Khaled hit live: the sheet's own `unit` column (real file:
     * "PAIR" on every row) was read into $unit but never actually wired to
     * Product.unit_id, so every imported article silently fell back to the
     * generic "পিস" (pieces) display default — wrong for a shoe shop. A
     * shoe is sold by the pair, not the piece; English label in the sheet
     * -> this shop-appropriate Bengali/English pair.
     */
    private const UNIT_SYNONYMS = [
        'PAIR' => ['জোড়া', 'Pair'],
        'PAIRS' => ['জোড়া', 'Pair'],
        'PCS' => ['পিস', 'Pcs'],
        'PIECE' => ['পিস', 'Pcs'],
        'PIECES' => ['পিস', 'Pcs'],
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("ফাইল পাওয়া যায়নি: {$path}");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<id> দিতে হবে — কোন শপে ইমপোর্ট হবে তা স্পষ্ট করে বলতে হয়, অনুমান করা হয় না।');

            return self::FAILURE;
        }
        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        if ($rows === null) {
            $this->error('"Products" শিট পাওয়া যায়নি এই ফাইলে।');

            return self::FAILURE;
        }

        $report = [
            'articles' => 0, 'variants' => 0, 'skipped_example_rows' => 0, 'skipped_blank_rows' => 0,
            'stock_mismatches' => [], 'cost_mismatches' => [], 'cost_filled_from_barcode' => [],
            'undecodable_cost_codes' => [], 'unparseable_grid_cells' => [], 'new_categories' => [],
            'new_suppliers' => [], 'already_exists' => [], 'errors' => [],
        ];

        $categoryCodeCache = []; // name|en name -> ProductCategory
        $supplierCache = []; // short_code -> Supplier
        $unitCache = []; // sheet's own unit text -> Unit

        foreach ($rows as $rowNum => $row) {
            $mfr = trim((string) ($row['mfr'] ?? ''));
            $nameEn = trim((string) ($row['name_en'] ?? ''));

            if ($mfr === '' && $nameEn === '') {
                $report['skipped_blank_rows']++;

                continue;
            }
            if (stripos($nameEn, 'Kids Sneaker') !== false) {
                $report['skipped_example_rows']++;

                continue;
            }
            if ($mfr === '' || $nameEn === '') {
                $report['errors'][] = "সারি {$rowNum}: ম্যানুফ্যাকচার বা নাম খালি, বাদ দেওয়া হলো।";

                continue;
            }

            try {
                $this->processRow($shop, $row, $rowNum, $report, $categoryCodeCache, $supplierCache, $unitCache, $isDryRun);
            } catch (\Throwable $e) {
                $report['errors'][] = "সারি {$rowNum}: ".$e->getMessage();
            }
        }

        if (! $isDryRun) {
            foreach ($categoryCodeCache as $category) {
                if ($category->code) {
                    // advance the shared counter past every imported serial for
                    // this category — see ArticleSerial::bumpFloor()'s own docblock
                    $maxSerial = $report['_max_serial_by_code'][$category->code] ?? null;
                    if ($maxSerial) {
                        ArticleSerial::bumpFloor($shop, $category->code, $maxSerial + 1);
                    }
                }
            }
            if ($shop->hasFeature('shared_catalog') && ! $shop->parent_shop_id) {
                foreach ($shop->branches as $branch) {
                    CatalogSync::syncToBranch($shop, $branch);
                }
            }
        }

        $this->printReport($report, $isDryRun);

        return self::SUCCESS;
    }

    private function processRow($shop, array $row, int $rowNum, array &$report, array &$categoryCodeCache, array &$supplierCache, array &$unitCache, bool $isDryRun): void
    {
        $mfr = strtoupper(trim((string) $row['mfr']));
        $categoryLabel = strtoupper(trim((string) ($row['category'] ?? '')));
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $sizeRange = trim((string) ($row['size'] ?? ''));
        $rawCost = $row['cost'] ?? null;
        $price = (float) ($row['price'] ?? 0);
        $discountPrice = $row['discount_price'] ?? null;
        $statedStock = $row['stock'] ?? null;
        $reorderPoint = $row['reorder_point'] ?? null;
        $unit = trim((string) ($row['unit'] ?? 'পিস'));

        // --- category: reuse an existing one by English/Bengali synonym, never duplicate ---
        $category = $this->resolveCategory($shop, $categoryLabel, $categoryCodeCache, $report);

        // --- category barcode-code: extracted from the row's OWN barcode (e.g. "L" from "SMS-L-10001-...") so imported articles keep using exactly the code already baked into their barcodes ---
        if (! $category->code && preg_match('/^[A-Z0-9]+-([A-Z0-9]+)-\d+-/', $barcode, $m)) {
            $category->code = $m[1];
            if (! $isDryRun) {
                $category->save();
            }
        }

        // --- supplier/manufacturer ---
        $supplier = $supplierCache[$mfr] ?? null;
        if (! $supplier) {
            $supplier = Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->where('short_code', $mfr)->first();
            if (! $supplier) {
                $supplier = new Supplier(['shop_id' => $shop->id, 'name' => $mfr, 'short_code' => $mfr, 'payable' => 0]);
                $report['new_suppliers'][] = $mfr;
                if (! $isDryRun) {
                    $supplier->save();
                }
            }
            $supplierCache[$mfr] = $supplier;
        }

        // --- unit: the sheet's own text (e.g. "PAIR"), never left unresolved ---
        $unitKey = strtoupper($unit);
        $unitObj = $unitCache[$unitKey] ?? null;
        if (! $unitObj) {
            [$unitName, $unitNameEn] = self::UNIT_SYNONYMS[$unitKey] ?? [$unit, $unit];
            $unitObj = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $unitName)->first();
            if (! $unitObj) {
                $unitObj = new Unit(['shop_id' => $shop->id, 'name' => $unitName, 'name_en' => $unitNameEn]);
                if (! $isDryRun) {
                    $unitObj->save();
                }
            }
            $unitCache[$unitKey] = $unitObj;
        }

        // --- cost: validate against the barcode's own cost-code, fill if blank ---
        $hasCostCodeSegment = (bool) preg_match('/-([A-Z]+)$/', $barcode, $m);
        $decodedCost = $hasCostCodeSegment ? ArticleBarcode::decodeCost($shop, $m[1]) : null;
        $cost = $rawCost !== null && $rawCost !== '' ? (int) round((float) $rawCost) : null;
        if ($cost === null && $decodedCost !== null) {
            $cost = $decodedCost;
            $report['cost_filled_from_barcode'][] = "সারি {$rowNum} ({$row['name_en']}): cost খালি ছিল, বারকোড থেকে {$decodedCost} বসানো হলো।";
        } elseif ($cost !== null && $decodedCost !== null && $cost !== $decodedCost) {
            $report['cost_mismatches'][] = "সারি {$rowNum} ({$row['name_en']}): ফাইলের cost={$cost}, কিন্তু বারকোড ডিকোড করলে {$decodedCost} — মিলছে না (ঠিক করা হয়নি)।";
        } elseif ($decodedCost === null && $hasCostCodeSegment) {
            // covers BOTH: cost given but barcode undecodable, AND cost
            // blank with an undecodable barcode (the latter previously
            // fell through every branch silently, leaving cost at 0 with
            // no warning at all — caught by this command's own tests)
            $report['undecodable_cost_codes'][] = "সারি {$rowNum} ({$row['name_en']}): বারকোডের cost-code '{$m[1]}' এখনো পুরোপুরি ডিকোড করা যাচ্ছে না (কিছু অক্ষরের সংখ্যা এখনো সেট করা হয়নি)।";
        }

        // --- name: normalize "L-10001"/"L10003" to one canonical "L-100XX" form, driven by the barcode's own serial ---
        $canonicalName = $row['name_en'];
        $serial = null;
        if (preg_match('/-([A-Z0-9]+)-(\d+)-/', $barcode, $m)) {
            [$catCode, $serial] = [$m[1], (int) $m[2]];
            $canonicalName = "{$catCode}-{$serial}";
            $maxKey = '_max_serial_by_code';
            $report[$maxKey][$catCode] = max($report[$maxKey][$catCode] ?? 0, $serial);
        }

        // --- idempotency: an article whose barcode already exists in this
        // shop is SKIPPED, never recreated — the entry sheet is a living
        // master list, so a re-run naturally contains rows imported earlier
        // (live-caught: L-10001/L-10002 were already selling on shop #80
        // when the updated sheet arrived; a bare create would have made
        // duplicate barcodes). Stock is deliberately NOT touched either:
        // the live number has moved with real sales since the sheet was
        // written, and the sheet's stale count must never clobber it. ---
        if ($barcode !== '') {
            $existing = Product::withoutGlobalScopes()
                ->where('shop_id', $shop->id)->where('barcode', $barcode)->first();
            if ($existing) {
                $report['already_exists'][] = "সারি {$rowNum} ({$canonicalName}): এই বারকোডের আর্টিকেল আগে থেকেই আছে — বাদ (লাইভ স্টক অপরিবর্তিত)।";

                return;
            }
        }

        // --- size range -> list ---
        $sizes = $this->parseSizeRange($sizeRange);

        // --- color x size grid ---
        $variantSpecs = []; // [['color' => ..., 'size' => ..., 'stock' => int], ...]
        $gridTotal = 0;
        foreach (self::COLOR_COLUMNS as $colHeader) {
            $cell = trim((string) ($row[$colHeader] ?? ''));
            if ($cell === '' || strpos($cell, '#') !== false) {
                continue; // this article doesn't come in this color
            }
            $colorName = self::COLOR_LABEL_FIX[$colHeader] ?? $colHeader;
            preg_match_all('/(\d+)\D*?\(([^)]*)\)/u', $cell, $matches, PREG_SET_ORDER);
            if (! $matches) {
                $report['unparseable_grid_cells'][] = "সারি {$rowNum} ({$row['name_en']}), রং {$colorName}: গ্রিড টেক্সট পার্স করা গেলো না: \"{$cell}\"";

                continue;
            }
            foreach ($matches as $pair) {
                $size = $pair[1];
                $qtyText = strtoupper(trim($pair[2]));
                $qty = $this->parseQty($qtyText);
                if ($qty === null) {
                    $report['unparseable_grid_cells'][] = "সারি {$rowNum} ({$row['name_en']}), রং {$colorName}, সাইজ {$size}: পরিমাণ বোঝা গেলো না: \"{$pair[2]}\"";

                    continue;
                }
                $variantSpecs[] = ['color' => $colorName, 'size' => $size, 'stock' => $qty];
                $gridTotal += $qty;
            }
        }

        if ($statedStock !== null && $statedStock !== '' && (int) $statedStock !== $gridTotal) {
            $report['stock_mismatches'][] = "সারি {$rowNum} ({$row['name_en']}): stock কলামে {$statedStock}, কিন্তু রং/সাইজ গ্রিড যোগ করলে {$gridTotal} — গ্রিডের যোগফলই ব্যবহার হবে।";
        }

        $report['articles']++;
        $report['variants'] += count($variantSpecs);

        if ($isDryRun) {
            return;
        }

        DB::transaction(function () use ($shop, $category, $supplier, $unitObj, $canonicalName, $barcode, $cost, $price, $discountPrice, $reorderPoint, $variantSpecs, $gridTotal) {
            $product = Product::create([
                'shop_id' => $shop->id,
                'category_id' => $category->id,
                'supplier_id' => $supplier->id,
                'unit_id' => $unitObj->id,
                'name' => $canonicalName,
                'name_en' => $canonicalName,
                'barcode' => $barcode ?: null,
                'cost' => $cost ?? 0,
                'price' => $price,
                'discount_price' => $discountPrice !== null && $discountPrice !== '' ? (float) $discountPrice : null,
                'reorder_point' => $reorderPoint !== null && $reorderPoint !== '' ? (int) $reorderPoint : null,
                'stock' => 0, // a variant product's own stock is a live-maintained sum, same convention as ProductImportController
            ]);

            foreach ($variantSpecs as $spec) {
                ProductVariant::create([
                    'shop_id' => $shop->id,
                    'product_id' => $product->id,
                    'size' => $spec['size'],
                    'color' => $spec['color'],
                    'barcode' => ArticleBarcode::buildVariantBarcode($shop, $barcode, $spec['color'], $spec['size']),
                    'stock' => $spec['stock'],
                    'price' => null, // same as the article price unless later overridden
                    'cost' => null,
                ]);
            }

            $product->stock = $gridTotal;
            $product->saveQuietly();

            // docs/ARTICLE_SERIES_PLAN.md §9 — the scannable "tier 2" sticker barcode, one per color
            foreach (array_unique(array_filter(array_column($variantSpecs, 'color'))) as $color) {
                ColorBarcodeResolver::resolve($shop, $product, $color, $barcode);
            }
        });
    }

    private function resolveCategory($shop, string $label, array &$cache, array &$report): ProductCategory
    {
        if (isset($cache[$label])) {
            return $cache[$label];
        }

        // $label itself is always in the lookup list: when no synonym
        // category exists, the fallback below CREATES one named $label —
        // without $label here, a re-run couldn't find that very category
        // and would try to create it again, colliding on the unique
        // (shop_id, code) index (caught by the re-run idempotency test).
        $synonyms = array_merge([$label], self::CATEGORY_SYNONYMS[$label] ?? []);
        $category = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where(function ($q) use ($synonyms) {
                foreach ($synonyms as $s) {
                    $q->orWhere('name', $s)->orWhere('name_en', $s);
                }
            })->first();

        if (! $category) {
            $category = ProductCategory::create([
                'shop_id' => $shop->id,
                'name' => $label,
                'name_en' => $label,
                'emoji' => '👟',
            ]);
            $report['new_categories'][] = $label;
        }

        $cache[$label] = $category;

        return $category;
    }

    private function parseQty(string $qtyText): ?int
    {
        if (preg_match('/^SOLD$/i', $qtyText)) {
            return 0;
        }
        // "1 & SOLD" / "1& SOLD" — ticket's own explicit rule: current stock is the leading number
        if (preg_match('/^(\d+)\s*&\s*SOLD$/i', $qtyText, $m)) {
            return (int) $m[1];
        }
        if (ctype_digit($qtyText)) {
            return (int) $qtyText;
        }

        return null;
    }

    private function parseSizeRange(string $range): array
    {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $range, $m)) {
            return range((int) $m[1], (int) $m[2]);
        }

        return $range !== '' ? [$range] : [];
    }

    /** @return array<int,array<string,mixed>>|null keyed by 1-based spreadsheet row number */
    private function readRows(string $path): ?array
    {
        $spreadsheet = IOFactory::load($path);
        if (! $spreadsheet->sheetNameExists('Products')) {
            return null;
        }

        $sheet = $spreadsheet->getSheetByName('Products');
        $headerMap = [
            'A' => 'mfr', 'B' => 'name_en', 'C' => 'barcode', 'D' => 'category', 'E' => 'unit',
            'F' => 'cost', 'G' => 'price', 'H' => 'discount_price', 'I' => 'stock', 'J' => 'reorder_point', 'K' => 'size',
            'L' => 'BLACK', 'M' => 'WHITE', 'N' => 'BROWN', 'O' => 'COFFE', 'P' => 'SILVER',
            'Q' => 'KHAKI', 'R' => 'GOLDEN', 'S' => 'MAROON', 'T' => 'GREY',
        ];

        $rows = [];
        // Deliberately NOT trusting getHighestRow() — real-file confirmed
        // artifact (docs/ARTICLE_SERIES_PLAN.md): Excel reported 1997 rows
        // for a sheet with only 10 actually filled in (conditional-format
        // bleed). Stop at the first row with no manufacturer AND no name.
        $blankStreak = 0;
        for ($r = 2; $blankStreak < 5; $r++) {
            $row = [];
            foreach ($headerMap as $col => $key) {
                $row[$key] = $sheet->getCell("{$col}{$r}")->getValue();
            }
            if (trim((string) ($row['mfr'] ?? '')) === '' && trim((string) ($row['name_en'] ?? '')) === '') {
                $blankStreak++;

                continue;
            }
            $blankStreak = 0;
            $rows[$r] = $row;
        }

        return $rows;
    }

    private function printReport(array $report, bool $isDryRun): void
    {
        $this->newLine();
        $this->info($isDryRun ? '--- প্রিভিউ (কিছু লেখা হয়নি) ---' : '--- ইমপোর্ট সম্পন্ন ---');
        $this->line("আর্টিকেল: {$report['articles']}, ভ্যারিয়েন্ট: {$report['variants']}");
        $this->line("বাদ দেওয়া ফাঁকা সারি: {$report['skipped_blank_rows']}, উদাহরণ সারি বাদ: {$report['skipped_example_rows']}");

        $this->printList('আগে থেকেই আছে — বাদ (স্টক অপরিবর্তিত)', $report['already_exists']);
        $this->printList('নতুন ক্যাটাগরি তৈরি হবে', array_unique($report['new_categories']));
        $this->printList('নতুন ম্যানুফ্যাকচার তৈরি হবে', array_unique($report['new_suppliers']));
        $this->printList('stock কলাম বনাম গ্রিড যোগফল মিসম্যাচ', $report['stock_mismatches']);
        $this->printList('cost কলাম বনাম বারকোড ডিকোড মিসম্যাচ', $report['cost_mismatches']);
        $this->printList('cost বারকোড থেকে বসানো হয়েছে', $report['cost_filled_from_barcode']);
        $this->printList('cost-code এখনো পুরোপুরি ডিকোড করা যায়নি', $report['undecodable_cost_codes']);
        $this->printList('গ্রিড সেল পার্স করা যায়নি', $report['unparseable_grid_cells']);
        $this->printList('ত্রুটি (বাদ দেওয়া সারি)', $report['errors']);

        if ($isDryRun) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ। ঠিক থাকলে Khaled "ঠিক আছে" বললে তবেই --dry-run ছাড়া চালানো হবে।');
        }
    }

    private function printList(string $title, array $items): void
    {
        if (! $items) {
            return;
        }
        $this->newLine();
        $this->warn("{$title} ({$this->countOrSame($items)}):");
        foreach (array_slice($items, 0, 30) as $item) {
            $this->line('  - '.$item);
        }
        if (count($items) > 30) {
            $this->line('  ... আরও '.(count($items) - 30).' টা');
        }
    }

    private function countOrSame(array $items): int
    {
        return count($items);
    }
}
