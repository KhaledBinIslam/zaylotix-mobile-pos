<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Exports\ArrayExport;
use App\Models\Customer;
use App\Models\WhatsappCredential;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * A plain customer-browsing tool — separate on purpose from Customers/Index
 * (the due-collection ledger, reached via the bottom nav's "বাকি" tab).
 * Reported live: the More menu's "কাস্টমার তালিকা" link landed on a page
 * titled/framed entirely around debt, which isn't what "customer list"
 * means to an owner who just wants to browse/select/message customers.
 * Select → bulk WhatsApp reuses WhatsappBulkController::send() as-is
 * (same route, same owner+feature:whatsapp_bulk gate) rather than a
 * second send implementation.
 */
class CustomerListController extends Controller
{
    public function index()
    {
        $shopId = Tenancy::id();
        $credential = WhatsappCredential::where('shop_id', $shopId)->where('is_active', true)->first();

        return Inertia::render('App/CustomerList/Index', [
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone', 'due', 'total_spent', 'visits']),
            'whatsappApiReady' => (bool) $credential,
        ]);
    }

    /** Same row shape as ExportController::dueRows(), just filtered to the ids the owner actually selected. */
    public function exportSelected(Request $request)
    {
        $data = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
        ]);

        $rows = [['Customer', 'Phone', 'Total Bought', 'Visits', 'Due']];
        foreach (Customer::whereIn('id', $data['customer_ids'])->get() as $c) {
            $rows[] = [$c->name, $c->phone, $c->total_spent, $c->visits, $c->due];
        }

        $format = $request->get('format', 'xlsx') === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $ext = $format === ExcelFormat::CSV ? 'csv' : 'xlsx';

        return Excel::download(new ArrayExport($rows), "customer-list-selected.{$ext}", $format);
    }
}
