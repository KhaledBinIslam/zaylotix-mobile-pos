<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArraySheetExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private string $title, private array $columns, private array $rows) {}

    public function array(): array
    {
        return array_map(fn ($row) => array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, array_values($row)), $this->rows);
    }

    public function headings(): array
    {
        return $this->columns;
    }

    public function title(): string
    {
        return $this->title;
    }
}
