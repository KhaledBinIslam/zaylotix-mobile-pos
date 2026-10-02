<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArrayExport implements FromArray, WithTitle
{
    // $title is only used when this sheet is one of several inside
    // AllReportsExport's combined workbook — a plain single-report
    // download (ExportController::download) ignores the sheet tab name
    public function __construct(private array $rows, private string $title = 'Sheet1') {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }
}
