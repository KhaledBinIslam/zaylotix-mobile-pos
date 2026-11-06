<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEAMAN Phase 6 — imports the owner's hand-written product list from
 * database/data/seaman-products.csv (committed in the repo, so the server
 * command needs no Bengali and no uploads on the command line).
 *
 * The owner's rules, enforced here:
 *  - rows with the same product name + sub-category become ONE product;
 *    option_value / brand / colors / sizes become its variants
 *    ('|' separates lists, '39-44' is a size range, colour × size expands)
 *  - stock is 0 for everything — the owner adds stock himself (Phase 6B)
 *  - a VERIFY remark never blocks the import: it lands on the product's
 *    internal_note and in the "check these" report at the end
 *  - a row that only differs by supplier/price is the same shoe from a
 *    second supplier: no duplicate variants — noted on the product, the
 *    supplier still created (real cost is recorded at stock-in anyway)
 *  - idempotent: a product that already exists is skipped untouched,
 *    so running twice can neither duplicate nor clobber owner edits
 *  - --dry-run runs the whole thing and rolls back, printing the plan
 */
class ImportSeamanProducts extends Command
{
    protected $signature = 'products:import-seaman {--shop=} {--dry-run}';

    protected $description = 'SEAMAN Phase 6: import the 92-item product list from database/data/seaman-products.csv';

    private const OWNER_PHONE = '01833722077';

    private const UNITS = [
        'pcs' => ['পিস', 'Pieces'],
        'pair' => ['জোড়া', 'Pair'],
        'roll' => ['রোল', 'Roll'],
        'set' => ['সেট', 'Set'],
        'dozen' => ['ডজন', 'Dozen'],
    ];

    /** @var array<string, int> */
    private array $unitIds = [];

    /** @var array<string, int> */
    private array $brandIds = [];

    /** @var array<string, int> */
    private array $supplierIds = [];

    private int $shopId = 0;

    private array $checkThese = [];

    private array $counts = ['products' => 0, 'variants' => 0, 'skipped' => 0, 'categories' => 0, 'suppliers' => 0, 'brands' => 0];

    public function handle(): int
    {
        $shop = $this->resolveShop();
        if (! $shop) {
            $this->error('SEAMAN shop khuje paoa jayni. --shop=ID diye chalaan.');

            return self::FAILURE;
        }
        $this->shopId = $shop->id;

        $path = database_path('data/seaman-products.csv');
        if (! is_file($path)) {
            $this->error('CSV file nei: '.$path);

            return self::FAILURE;
        }

        $groups = $this->groupRows($this->parseCsv($path));
        $dry = (bool) $this->option('dry-run');

        try {
            DB::transaction(function () use ($groups, $dry) {
                foreach ($groups as $group) {
                    $this->importGroup($group);
                }
                if ($dry) {
                    throw new \RuntimeException('__DRY_RUN__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__DRY_RUN__') {
                throw $e;
            }
        }

        $this->line('');
        $this->info(($dry ? '[DRY RUN — kichui lekha hoyni] ' : '').'Import report — shop #'.$this->shopId.':');
        $this->table(['ki', 'koyta'], [
            ['notun ponno', $this->counts['products']],
            ['notun variant', $this->counts['variants']],
            ['age thekei chhilo (skip)', $this->counts['skipped']],
            ['notun category/sub-category', $this->counts['categories']],
            ['notun supplier', $this->counts['suppliers']],
            ['notun brand', $this->counts['brands']],
        ]);

        if ($this->checkThese !== []) {
            $this->line('');
            $this->warn('CHECK THESE — ei ponnogulor note dekhe app-ei thik korun ('.count($this->checkThese).' ta):');
            foreach ($this->checkThese as $line) {
                $this->line('  • '.$line);
            }
        }

        return self::SUCCESS;
    }

    private function resolveShop(): ?Shop
    {
        if ($this->option('shop')) {
            return Shop::find((int) $this->option('shop'));
        }

        $owner = User::where('phone', self::OWNER_PHONE)->where('role', 'owner')->first();

        return $owner?->shop;
    }

    // ---------------------------------------------------------------- CSV

    private function parseCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $rows = [];
        while (($raw = fgetcsv($fh)) !== false) {
            if (count($raw) < 2 || trim(implode('', $raw)) === '') {
                continue;
            }
            $row = array_combine($header, array_pad($raw, count($header), ''));
            $rows[] = array_map(fn ($v) => trim((string) $v), $row);
        }
        fclose($fh);

        return $rows;
    }

    /** Same product name + same sub-category = one product (the owner's own grouping rule). */
    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['sub_category'].'||'.$row['product']);
            $groups[$key] ??= [];
            $groups[$key][] = $row;
        }

        return array_values($groups);
    }

    /** '(same as above)' style placeholders are remarks, never data. */
    private function isPlaceholder(string $v): bool
    {
        return $v !== '' && str_starts_with($v, '(');
    }

    private function splitList(string $v): array
    {
        if ($v === '' || $this->isPlaceholder($v)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $v)), fn ($x) => $x !== ''));
    }

    /** '39-44' → 39..44; otherwise a '|' list used as-is (handles '20.5|21|…'). */
    private function expandSizes(string $v): array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $v, $m) && (int) $m[1] < (int) $m[2]) {
            return array_map('strval', range((int) $m[1], (int) $m[2]));
        }

        return $this->splitList($v);
    }

    private function num(string $v): ?float
    {
        $v = str_replace(',', '', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    // ------------------------------------------------------------- lookups

    private function categoryId(string $top, string $sub): int
    {
        $parent = ProductCategory::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $this->shopId, 'name' => $top, 'parent_id' => null],
        );
        if ($parent->wasRecentlyCreated) {
            $this->counts['categories']++;
        }

        if ($sub === '' || mb_strtolower($sub) === mb_strtolower($top)) {
            return $parent->id;
        }

        $child = ProductCategory::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $this->shopId, 'name' => $sub, 'parent_id' => $parent->id],
        );
        if ($child->wasRecentlyCreated) {
            $this->counts['categories']++;
        }

        return $child->id;
    }

    private function unitId(string $code): ?int
    {
        $code = mb_strtolower($code) ?: 'pcs';
        if (! isset(self::UNITS[$code])) {
            $code = 'pcs';
        }
        if (! isset($this->unitIds[$code])) {
            [$bn, $en] = self::UNITS[$code];
            $unit = Unit::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $this->shopId, 'code' => $code],
                ['name' => $bn, 'name_en' => $en],
            );
            $this->unitIds[$code] = $unit->id;
        }

        return $this->unitIds[$code];
    }

    private function brandId(string $name): ?int
    {
        if ($name === '' || $this->isPlaceholder($name)) {
            return null;
        }
        if (! isset($this->brandIds[mb_strtolower($name)])) {
            $brand = Brand::withoutGlobalScopes()->firstOrCreate(['shop_id' => $this->shopId, 'name' => $name]);
            if ($brand->wasRecentlyCreated) {
                $this->counts['brands']++;
            }
            $this->brandIds[mb_strtolower($name)] = $brand->id;
        }

        return $this->brandIds[mb_strtolower($name)];
    }

    private function supplierId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        if (! isset($this->supplierIds[mb_strtolower($name)])) {
            $supplier = Supplier::withoutGlobalScopes()->firstOrCreate(['shop_id' => $this->shopId, 'name' => $name]);
            if ($supplier->wasRecentlyCreated) {
                $this->counts['suppliers']++;
            }
            $this->supplierIds[mb_strtolower($name)] = $supplier->id;
        }

        return $this->supplierIds[mb_strtolower($name)];
    }

    // ------------------------------------------------------------- import

    private function importGroup(array $rows): void
    {
        $first = $rows[0];
        $name = $first['product'];
        $categoryId = $this->categoryId($first['category'], $first['sub_category']);

        $existing = Product::withoutGlobalScopes()
            ->where('shop_id', $this->shopId)->where('name', $name)->where('category_id', $categoryId)
            ->exists();
        if ($existing) {
            $this->counts['skipped']++;

            return;
        }

        $notes = [];

        // parse every row once
        $parsed = [];
        foreach ($rows as $row) {
            foreach (['colors', 'sizes', 'option_value', 'brand'] as $field) {
                if ($this->isPlaceholder($row[$field] ?? '')) {
                    $notes[] = 'ref '.$row['ref'].' — '.$field.': '.$row[$field];
                }
            }

            $pp = $this->num($row['purchase_price']);
            $sp = $this->num($row['selling_price']);
            if ($row['purchase_price'] !== '' && $pp === null) {
                $notes[] = 'ref '.$row['ref'].' — kena dam porha jayni: "'.$row['purchase_price'].'" — 0 bosano hoyeche';
            }
            if ($row['selling_price'] !== '' && $sp === null) {
                $notes[] = 'ref '.$row['ref'].' — bikri dam porha jayni: "'.$row['selling_price'].'" — 0 bosano hoyeche';
            }
            if ($row['purchase_price'] === '') {
                $notes[] = 'ref '.$row['ref'].' — kena dam faka (Nil) — 0 bosano hoyeche';
            }
            if ($row['selling_price'] === '') {
                $notes[] = 'ref '.$row['ref'].' — bikri dam faka (Nil) — 0 bosano hoyeche';
            }

            if (($row['verify'] ?? '') !== '') {
                $notes[] = 'ref '.$row['ref'].' — '.$row['verify'];
            }

            $suppliers = $this->splitList($row['supplier']);
            if (count($suppliers) > 1) {
                $notes[] = 'ref '.$row['ref'].' — supplier onek: '.implode(', ', $suppliers);
            }

            $parsed[] = [
                'ref' => $row['ref'],
                'options' => $this->splitList($row['option_value']),
                'option_name' => $row['option_name'],
                'colors' => $this->splitList($row['colors']),
                'sizes' => $this->expandSizes($row['sizes']),
                'brand' => $this->isPlaceholder($row['brand']) ? '' : $row['brand'],
                'suppliers' => $suppliers,
                'cost' => $pp ?? 0.0,
                'price' => $sp ?? 0.0,
                'unit' => $row['unit'],
            ];
        }

        // a later row whose axes are each empty-or-identical to an earlier
        // kept row is the same item from another supplier / price source
        $kept = [];
        foreach ($parsed as $p) {
            $alt = false;
            foreach ($kept as $k) {
                $same = fn ($field) => $p[$field] === [] || $p[$field] === $k[$field];
                if ($same('options') && $same('colors') && $same('sizes') && ($p['brand'] === '' || $p['brand'] === $k['brand'])) {
                    $alt = true;
                    break;
                }
            }
            if ($alt && $kept !== []) {
                $src = $p['suppliers'] !== [] ? implode(', ', $p['suppliers']) : ($p['brand'] ?: 'onno source');
                $notes[] = 'ref '.$p['ref'].' — bikolpo source: '.$src.' — kena '.$p['cost'].', bikri '.$p['price'];
                foreach ($p['suppliers'] as $s) {
                    $this->supplierId($s);
                }

                continue;
            }
            $kept[] = $p;
        }

        // ---- axis assignment --------------------------------------------
        $allColors = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['colors'], $kept))));
        $allSizes = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['sizes'], $kept))));
        $allOptions = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['options'], $kept))));
        $brands = array_values(array_unique(array_filter(array_map(fn ($p) => $p['brand'], $kept))));
        $optionName = collect($kept)->pluck('option_name')->filter()->first() ?: 'Type';

        $sizeAxis = $allSizes !== [] ? 'sizes' : null;
        $colorAxis = count($allColors) > 1 ? 'colors' : null;

        $option1Label = null; // color column's name
        $option2Label = null; // size column's name
        $optionAxis = null;

        if ($allOptions !== []) {
            if ($sizeAxis === null) {
                $sizeAxis = 'options';
                $option2Label = $optionName;
                $optionAxis = 'size';
            } elseif ($colorAxis === null) {
                // a single lone colour steps aside for a real option axis
                if (count($allColors) === 1) {
                    $notes[] = 'rong: '.$allColors[0].' (option axis-er jonno note-e rakha holo)';
                    foreach ($kept as &$k) {
                        $k['colors'] = [];
                    }
                    unset($k);
                }
                $colorAxis = 'options';
                $option1Label = $optionName;
                $optionAxis = 'color';
            } else {
                $notes[] = 'option "'.$optionName.'" rakha gelo na (rong+size duto-i ache) — note: '.implode(', ', $allOptions);
                foreach ($kept as &$k) {
                    $k['options'] = [];
                }
                unset($k);
            }
        }

        // keep a lone colour (e.g. Yellow gumboot) when the column is free
        if ($colorAxis === null && count($allColors) === 1) {
            $colorAxis = 'colors';
        }

        // ---- does brand need to be an axis? -----------------------------
        // axes are passed IN (not closed over): the brand-axis retry below
        // reassigns them, and a `use` capture would still see the old value
        $expand = function (?string $cAxis, ?string $sAxis) use ($kept): array {
            $variants = [];
            $collision = false;
            foreach ($kept as $p) {
                $colorVals = $cAxis === '__brand__'
                    ? [$p['brand'] ?: 'Nil']
                    : ($cAxis ? ($p[$cAxis] ?: [null]) : [null]);
                $sizeVals = $sAxis === '__brand__'
                    ? [$p['brand'] ?: 'Nil']
                    : ($sAxis ? ($p[$sAxis] ?: [null]) : [null]);

                foreach ($colorVals as $c) {
                    foreach ($sizeVals as $s) {
                        $key = $c.'||'.$s;
                        if (isset($variants[$key])) {
                            if (($variants[$key]['brand'] ?? '') !== $p['brand']) {
                                $collision = true;
                            }

                            continue; // first row wins; later row was already noted or is a true dup
                        }
                        $variants[$key] = ['color' => $c, 'size' => $s, 'cost' => $p['cost'], 'price' => $p['price'], 'brand' => $p['brand'], 'ref' => $p['ref']];
                    }
                }
            }

            return [$variants, $collision];
        };

        [$variants, $collision] = $expand($colorAxis, $sizeAxis);

        if ($collision && count($brands) > 1) {
            if ($colorAxis === null) {
                $colorAxis = '__brand__';
                $option1Label = 'Brand';
            } elseif ($sizeAxis === null) {
                $sizeAxis = '__brand__';
                $option2Label = 'Brand';
            } elseif ($colorAxis === 'colors' && count($allColors) === 1) {
                $notes[] = 'rong: '.$allColors[0].' (brand axis-er jonno note-e rakha holo)';
                $colorAxis = '__brand__';
                $option1Label = 'Brand';
            }
            [$variants, $stillColliding] = $expand($colorAxis, $sizeAxis);
            if ($stillColliding) {
                $notes[] = 'brand gulo alada kora gelo na — app-e variant dekhe thik korun: '.implode(', ', $brands);
            }
        }

        // ---- write -------------------------------------------------------
        $isPlain = count($variants) === 1 && reset($variants)['color'] === null && reset($variants)['size'] === null;

        // the product carries a brand only when EVERY row agrees on it —
        // a half-branded group (one BD apron, one unbranded) stays Nil at
        // product level and brands live on the variants instead
        $uniformBrand = count($brands) === 1 && collect($kept)->every(fn ($p) => $p['brand'] !== '');

        $product = Product::create([
            'shop_id' => $this->shopId,
            'name' => $name,
            'category_id' => $categoryId,
            'unit_id' => $this->unitId($first['unit']),
            'cost' => $kept[0]['cost'],
            'price' => $kept[0]['price'],
            'stock' => 0,
            'brand_id' => $uniformBrand ? $this->brandId($brands[0]) : null,
            'supplier_id' => $kept[0]['suppliers'] !== [] ? $this->supplierId($kept[0]['suppliers'][0]) : null,
            'option1_label' => $option1Label,
            'option2_label' => $option2Label,
            'internal_note' => $notes !== [] ? implode("\n", array_unique($notes)) : null,
        ]);
        $this->counts['products']++;

        foreach ($kept as $p) {
            foreach ($p['suppliers'] as $s) {
                $this->supplierId($s);
            }
        }

        if (! $isPlain) {
            foreach ($variants as $v) {
                ProductVariant::create([
                    'shop_id' => $this->shopId,
                    'product_id' => $product->id,
                    'color' => $v['color'],
                    'size' => $v['size'],
                    'cost' => $v['cost'],
                    'price' => $v['price'],
                    'stock' => 0,
                    'brand_id' => $uniformBrand ? null : $this->brandId($v['brand']),
                ]);
                $this->counts['variants']++;
            }
        }

        if ($notes !== []) {
            $this->checkThese[] = 'ref '.$first['ref'].' — '.$name.' ('.count(array_unique($notes)).' ta note)';
        }
    }
}
