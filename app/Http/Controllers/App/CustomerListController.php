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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // updated_at doubles as "last activity" (bumped on every sale/
            // payment elsewhere) and loyalty_points added so the list's new
            // segment filters (top spenders, most visits, highest due,
            // inactive) — see docs/FIX_PLAN.md Phase 7 — have real data to
            // sort/filter by; this page itself still does no
            // filtering/sorting server-side, same as before.
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone', 'due', 'total_spent', 'visits', 'loyalty_points', 'updated_at', 'no_marketing']),
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

        if ($request->get('format') === 'vcf') {
            return $this->exportVcard($data['customer_ids']);
        }

        $rows = [['Customer', 'Phone', 'Total Bought', 'Visits', 'Due']];
        foreach (Customer::whereIn('id', $data['customer_ids'])->get() as $c) {
            $rows[] = [$c->name, $c->phone, $c->total_spent, $c->visits, $c->due];
        }

        $format = $request->get('format', 'xlsx') === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $ext = $format === ExcelFormat::CSV ? 'csv' : 'xlsx';

        return Excel::download(new ArrayExport($rows), "customer-list-selected.{$ext}", $format);
    }

    /**
     * One multi-contact .vcf so these phones can be imported into the
     * device's Contacts app in a single action, then messaged all at once
     * via WhatsApp's own native "New broadcast" feature (which only lists
     * saved contacts) — see docs/WHATSAPP_BULK_PLAN.md option B. A customer
     * who opted out of marketing (no_marketing) is skipped, same as the
     * free wa.me queue and the WhatsApp Business API send.
     */
    private function exportVcard(array $customerIds): StreamedResponse
    {
        $customers = Customer::whereIn('id', $customerIds)
            ->where('no_marketing', false)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['name', 'phone']);

        return response()->streamDownload(function () use ($customers) {
            foreach ($customers as $c) {
                echo "BEGIN:VCARD\r\n";
                echo "VERSION:3.0\r\n";
                echo 'FN:'.str_replace(["\r", "\n"], ' ', $c->name)."\r\n";
                echo 'TEL;TYPE=CELL:'.$c->phone."\r\n";
                echo "END:VCARD\r\n";
            }
        }, 'customer-list-selected.vcf', ['Content-Type' => 'text/vcard']);
    }

    /** Owner+cashier (same perm:customers gate as the list itself) toggle — no separate customer-edit form exists, so this is a lightweight inline action from the row itself. */
    public function toggleMarketing(Request $request, Customer $customer)
    {
        $customer->update(['no_marketing' => ! $customer->no_marketing]);

        return back();
    }
}
