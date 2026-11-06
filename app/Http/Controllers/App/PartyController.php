<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shop;
use App\Support\PartyPdf;
use App\Support\PartyStatement;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

/**
 * SEAMAN Phase 5 — corporate parties (feature: parties). A party IS a
 * customer (party_type set), so due, payments, credit limits and POS
 * credit sales all reuse the one existing system; these pages only add
 * the party-shaped views: bill-wise due, the monthly statement with a
 * running balance, A4 PDFs and the WhatsApp share.
 *
 * HOUSE RULE: everything served here can end up in the party's hands —
 * supplier names and purchase prices must never appear in any of it.
 */
class PartyController extends Controller
{
    private const TYPES = ['company', 'person'];

    public function index()
    {
        $parties = Customer::whereNotNull('party_type')
            ->orderByDesc('due')->orderBy('name')
            ->get(['id', 'name', 'phone', 'due', 'party_type', 'company_name', 'contact_person', 'address', 'notes']);

        return Inertia::render('App/Parties/Index', [
            'parties' => $parties,
            'totalDue' => round((float) $parties->sum('due'), 2),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        // same phone-dup guard as CustomerController::store — one phone,
        // one customer row, or the due history silently splits in two
        $phone = $data['phone'] ?? null;
        if ($phone && ($existing = Customer::where('phone', $phone)->first())) {
            if ($existing->party_type) {
                return back()->withErrors(['phone' => "এই নম্বরে আগে থেকেই পার্টি আছে: {$existing->name}।"]);
            }

            // an existing plain customer becomes the party — their due and
            // history follow them; a second row would have split it
            $existing->update($data);

            return back()->with('success', "{$existing->name} এখন থেকে পার্টি — আগের বাকি/হিসাব একসাথেই থাকলো।");
        }

        Customer::create($data + ['due' => 0]);

        return back()->with('success', "{$data['name']} পার্টি হিসেবে যুক্ত হলো।");
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);
        abort_unless($customer->party_type, 404);

        $customer->update($this->validated($request));

        return back()->with('success', 'পার্টির তথ্য সংরক্ষণ করা হয়েছে।');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'party_type' => ['required', 'in:'.implode(',', self::TYPES)],
            'phone' => ['nullable', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['phone', 'company_name', 'contact_person', 'address', 'notes'] as $key) {
            $data[$key] = ($data[$key] ?? null) ?: null;
        }

        return $data;
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);
        abort_unless($customer->party_type, 404);

        // bill-wise list — same tendered/due-at-checkout math as
        // PaymentController::invoices(), but ALL non-voided bills (a fully
        // paid bill still belongs on the party's trade history)
        $bills = $customer->sales()
            ->whereNull('voided_at')
            ->with('payments')
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function ($sale) {
                $tendered = (float) $sale->payments->sum('amount');

                return [
                    'id' => $sale->id,
                    'invoice_no' => $sale->invoice_no,
                    'date' => (string) $sale->date,
                    'total' => (float) $sale->total,
                    'paid_at_checkout' => $tendered,
                    'due_at_checkout' => max(0, round((float) $sale->total - $tendered, 2)),
                    'tagged_paid' => (float) Payment::where('sale_id', $sale->id)->whereNull('voided_at')->whereNull('void_of_payment_id')->sum('amount'),
                ];
            });

        $payments = $customer->payments()
            ->with('user:id,name')
            ->latest('date')->latest('id')
            ->limit(200)
            ->get(['id', 'customer_id', 'user_id', 'sale_id', 'amount', 'due_before', 'due_after', 'method', 'date', 'voided_at', 'void_of_payment_id']);

        return Inertia::render('App/Parties/Show', [
            'party' => $customer->only(['id', 'name', 'phone', 'due', 'party_type', 'company_name', 'contact_person', 'address', 'notes']),
            'bills' => $bills,
            'payments' => $payments,
            'totalBilled' => round((float) $bills->sum('total'), 2),
        ]);
    }

    /** JSON preview of the statement the PDF will contain — same builder, so the screen never disagrees with the paper. */
    public function statement(Request $request, Customer $customer)
    {
        $this->authorize('view', $customer);
        abort_unless($customer->party_type, 404);

        [$from, $to] = $this->range($request);

        return response()->json(['from' => $from, 'to' => $to] + PartyStatement::build($customer, $from, $to));
    }

    public function statementPdf(Request $request, Customer $customer)
    {
        $this->authorize('view', $customer);
        abort_unless($customer->party_type, 404);

        [$from, $to] = $this->range($request);
        $pdf = PartyPdf::statement(Tenancy::shop(), $customer, $from, $to);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$customer->id.'-'.$from.'-'.$to.'.pdf"',
        ]);
    }

    public function billPdf(Customer $customer, Sale $sale)
    {
        $this->authorize('view', $customer);
        abort_unless($customer->party_type, 404);
        abort_unless((int) $sale->customer_id === (int) $customer->id, 404);
        abort_if($sale->voided_at, 404);

        $sale->load(['items', 'payments']);
        $pdf = PartyPdf::bill(Tenancy::shop(), $customer, $sale);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="bill-'.$sale->invoice_no.'.pdf"',
        ]);
    }

    /**
     * A signed, expiring, read-only link to the statement PDF — the
     * WhatsApp fallback when the device can't share the file itself.
     * The signature covers customer/from/to, so nothing else is reachable.
     */
    public function shareLink(Request $request, Customer $customer)
    {
        $this->authorize('view', $customer);
        abort_unless($customer->party_type, 404);

        [$from, $to] = $this->range($request);

        $url = URL::temporarySignedRoute('party.publicStatement', now()->addDays(7), [
            'customer' => $customer->id, 'from' => $from, 'to' => $to,
        ]);

        return response()->json(['url' => $url, 'expires_days' => 7]);
    }

    /**
     * The public end of shareLink() — NO auth, NO tenancy: everything is
     * resolved from the signed customer id, and the 'signed' middleware
     * already rejected any tampered or expired URL before this runs.
     */
    public function publicStatement(Request $request)
    {
        $customer = Customer::withoutGlobalScopes()
            ->whereKey($request->integer('customer'))
            ->whereNotNull('party_type')
            ->firstOrFail();

        $shop = Shop::findOrFail($customer->shop_id);
        abort_unless($shop->hasFeature('parties'), 404);

        $from = $request->query('from');
        $to = $request->query('to');
        abort_unless(is_string($from) && is_string($to)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), 404);

        // the statement builder reads tenant-scoped relations — run it as
        // the party's own shop, exactly like an authenticated request would
        Tenancy::set($shop->id);

        $pdf = PartyPdf::statement($shop, $customer, $from, $to);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="statement.pdf"',
        ]);
    }

    /** @return array{0: string, 1: string} from/to — defaults to this month so far ("Month end" is the spec's stated use). */
    private function range(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $from = (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : now()->startOfMonth()->toDateString();
        $to = (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ? $to : now()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }
}
