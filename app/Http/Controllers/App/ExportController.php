<?php

namespace App\Http\Controllers\App;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\Reports;
use App\Support\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    private const TITLES = [
        'sales' => 'বিক্রয় হিসাব', 'stock' => 'স্টক তালিকা', 'due' => 'বাকির খাতা',
        'exp' => 'খরচ হিসাব', 'damage' => 'ক্ষতির হিসাব', 'return' => 'ফেরত হিসাব', 'pl' => 'লাভ-ক্ষতি',
    ];

    public function download(Request $request, string $kind)
    {
        [$rows, $name] = match ($kind) {
            'sales' => [$this->salesRows(), 'bikroy-hisab'],
            'stock' => [$this->stockRows(), 'stock-talika'],
            'due' => [$this->dueRows(), 'bakir-khata'],
            'exp' => [$this->expenseRows(), 'khoroch-hisab'],
            'damage' => [$this->damageRows(), 'khoti-hisab'],
            'return' => [$this->returnRows(), 'ferot-hisab'],
            'pl' => [$this->plRows(), 'labh-khoti'],
            default => abort(404),
        };

        if ($request->get('format') === 'pdf') {
            return $this->downloadPdf($rows, $name, self::TITLES[$kind]);
        }

        $format = $request->get('format', 'xlsx') === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $ext = $format === ExcelFormat::CSV ? 'csv' : 'xlsx';

        return Excel::download(new ArrayExport($rows), "{$name}.{$ext}", $format);
    }

    private function downloadPdf(array $rows, string $name, string $title)
    {
        $shop = Tenancy::shop();

        // embedded as a data URI (never fetched over the network) — dompdf
        // rendering a shop's own uploaded logo must never depend on an
        // outbound HTTP request succeeding at render time
        $logoUrl = null;
        if ($shop?->logo_path && Storage::disk('public')->exists($shop->logo_path)) {
            $mime = Storage::disk('public')->mimeType($shop->logo_path) ?: 'image/png';
            $logoUrl = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($shop->logo_path));
        }

        $pdf = Pdf::loadView('exports.pdf', [
            'rows' => $rows,
            'title' => $title,
            'shopName' => $shop?->name ?? 'Zaylotix POS',
            'logoUrl' => $logoUrl,
            'generatedAt' => now()->format('d M Y, h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$name}.pdf");
    }

    private function salesRows(): array
    {
        $rows = [['Invoice', 'Date', 'Time', 'Customer', 'Payment', 'Total', 'Profit']];
        foreach (Sale::with('customer')->orderByDesc('id')->get() as $s) {
            $rows[] = [$s->invoice_no, $s->date->toDateString(), $s->time, $s->customer?->name, $s->payment_mode, $s->total, $s->profit];
        }

        return $rows;
    }

    private function stockRows(): array
    {
        $rows = [['Product', 'Category', 'Barcode', 'Cost', 'Price', 'Stock', 'Stock Value']];
        foreach (Product::with('category')->get() as $p) {
            $rows[] = [$p->name, $p->category?->name, $p->barcode, $p->cost, $p->price, $p->stock, $p->cost * $p->stock];
        }

        return $rows;
    }

    private function dueRows(): array
    {
        $rows = [['Customer', 'Phone', 'Total Bought', 'Visits', 'Due']];
        foreach (Customer::get() as $c) {
            $rows[] = [$c->name, $c->phone, $c->total_spent, $c->visits, $c->due];
        }

        return $rows;
    }

    private function expenseRows(): array
    {
        $rows = [['Date', 'Category', 'Note', 'Paid from', 'Amount']];
        foreach (Expense::with('category')->get() as $x) {
            $rows[] = [$x->date->toDateString(), $x->category?->name, $x->memo, $x->method, $x->amount];
        }

        return $rows;
    }

    private function damageRows(): array
    {
        $rows = [['Date', 'Product', 'Qty', 'Reason', 'Loss']];
        foreach (Damage::with('product')->get() as $d) {
            $rows[] = [$d->date->toDateString(), $d->product?->name, $d->qty, $d->reason, $d->loss];
        }

        return $rows;
    }

    private function returnRows(): array
    {
        $rows = [['Date', 'Product', 'Qty', 'Refund', 'Phone']];
        foreach (SalesReturn::with('product')->get() as $r) {
            $rows[] = [$r->date->toDateString(), $r->product?->name, $r->qty, $r->refund, $r->phone];
        }

        return $rows;
    }

    private function plRows(): array
    {
        $stats = Reports::rangeStats('1970-01-01', now()->toDateString());

        return [
            ['Item', 'Amount'],
            ['Total Sales', $stats['salesAmt']],
            ['Cost of Goods', $stats['cogs']],
            ['Gross Profit', $stats['grossProfit']],
            ['Expenses', $stats['exp']],
            ['Damage/Wastage', $stats['dmg']],
            ['Returns', $stats['ret']],
            ['VAT', $stats['vat']],
            ['Net Profit', $stats['net']],
        ];
    }
}
