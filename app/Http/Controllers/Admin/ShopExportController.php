<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ShopDataExport;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\ShopSqlDump;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ShopExportController extends Controller
{
    /** Every table's rows for this shop only, as one downloadable workbook — for a human to read. */
    public function download(Shop $shop)
    {
        $filename = Str::slug($shop->name).'-data-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new ShopDataExport($shop), $filename);
    }

    /** The same shop's data as a portable .sql dump — real INSERT statements, for re-importing into a fresh install elsewhere. */
    public function downloadSql(Shop $shop)
    {
        $filename = Str::slug($shop->name).'-data-'.now()->format('Y-m-d').'.sql';
        $sql = ShopSqlDump::generate($shop);

        return response($sql, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
