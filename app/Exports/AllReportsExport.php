<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Every report ExportController already builds one-by-one, combined into
 * a single workbook — one sheet per report — so a shop owner who wants
 * everything doesn't have to download each one separately.
 */
class AllReportsExport implements WithMultipleSheets
{
    /** @param  array<string, array>  $sheets  sheet title => rows (row 0 is already the header row, same shape ExportController's own row-builders return) */
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        $result = [];
        foreach ($this->sheets as $title => $rows) {
            $result[] = new ArrayExport($rows, $title);
        }

        return $result;
    }
}
