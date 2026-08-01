<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk product upload from a spreadsheet — a shop with hundreds of SKUs
 * (a supershop or pharmacy) shouldn't have to type each one in by hand
 * through the mobile Add Product sheet. Matches on barcode within the shop
 * to decide create-vs-update; rows with no barcode are always created new
 * since there's nothing to match against.
 */
class ProductImportController extends Controller
{
    private const HEADERS = ['name', 'name_en', 'barcode', 'category', 'unit', 'cost', 'price', 'discount_price', 'stock', 'reorder_point', 'size', 'color'];

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens Bengali cells as UTF-8, not mojibake
            fputcsv($out, self::HEADERS);
            fputcsv($out, ['চিনি ১কেজি', 'Sugar 1kg', '8901030123456', 'মুদি', 'পিস', '60', '70', '', '100', '10', '', '']);
            fclose($out);
        }, 'product-import-template.csv');
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'ফাইলটি পড়া যায়নি।']);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'ফাইলটি খালি।']);
        }
        // strip a UTF-8 BOM if the first header cell carries one
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $columns = array_map(fn ($h) => strtolower(trim($h)), $header);

        $categoryCache = [];
        $unitCache = [];
        $added = 0;
        $updated = 0;
        $skipped = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $data = [];
            foreach ($columns as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $name = $data['name'] ?? '';
            $cost = $data['cost'] ?? '';
            $price = $data['price'] ?? '';
            $stock = $data['stock'] ?? '';

            if ($name === '' || $cost === '' || $price === '' || $stock === ''
                || ! is_numeric($cost) || ! is_numeric($price) || ! is_numeric($stock)) {
                $skipped[] = "সারি {$rowNum}: নাম/দাম/স্টক ঠিক নেই";

                continue;
            }

            DB::transaction(function () use ($data, $name, $cost, $price, $stock, &$categoryCache, &$unitCache, &$added, &$updated) {
                $categoryId = null;
                if (! empty($data['category'])) {
                    $key = mb_strtolower($data['category']);
                    if (! isset($categoryCache[$key])) {
                        $categoryCache[$key] = ProductCategory::firstOrCreate(
                            ['shop_id' => Tenancy::id(), 'name' => $data['category']],
                            ['name_en' => $data['category'], 'emoji' => '📦']
                        )->id;
                    }
                    $categoryId = $categoryCache[$key];
                }

                $unitId = null;
                if (! empty($data['unit'])) {
                    $key = mb_strtolower($data['unit']);
                    if (! isset($unitCache[$key])) {
                        $unitCache[$key] = Unit::firstOrCreate(
                            ['shop_id' => Tenancy::id(), 'name' => $data['unit']],
                            ['name_en' => $data['unit'], 'code' => null]
                        )->id;
                    }
                    $unitId = $unitCache[$key];
                }

                $barcode = $data['barcode'] ?? '';
                $existing = $barcode !== '' ? Product::where('barcode', $barcode)->first() : null;

                $fields = [
                    'name' => $name,
                    'name_en' => $data['name_en'] ?: $name,
                    'barcode' => $barcode ?: null,
                    'category_id' => $categoryId,
                    'unit_id' => $unitId,
                    'cost' => (float) $cost,
                    'price' => (float) $price,
                    'discount_price' => ($data['discount_price'] ?? '') !== '' ? (float) $data['discount_price'] : null,
                    'stock' => (int) $stock,
                    'reorder_point' => ($data['reorder_point'] ?? '') !== '' ? (int) $data['reorder_point'] : null,
                    'size' => $data['size'] ?: null,
                    'color' => $data['color'] ?: null,
                ];

                if ($existing) {
                    // a variant product's stock is a live-maintained sum of
                    // its variants — same invariant as the manual edit form,
                    // never let a bulk import drift it away from that sum
                    if ($existing->variants()->exists()) {
                        unset($fields['stock']);
                    }
                    $existing->update($fields);
                    $updated++;
                } else {
                    Product::create($fields + ['emoji' => '📦']);
                    $added++;
                }
            });
        }

        fclose($handle);

        $summary = "{$added}টি নতুন পণ্য যোগ হয়েছে, {$updated}টি হালনাগাদ হয়েছে।";
        if ($skipped) {
            $shown = array_slice($skipped, 0, 5);
            $summary .= ' বাদ পড়েছে '.count($skipped).'টি: '.implode('; ', $shown);
            if (count($skipped) > 5) {
                $summary .= ' ...';
            }
        }

        return back()->with($added + $updated > 0 ? 'success' : 'error', $summary);
    }
}
