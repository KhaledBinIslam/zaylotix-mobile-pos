<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ProductSerial;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Search a specific unit by IMEI — for after-sales service: is it still under warranty, and which sale/customer did it go to. */
class SerialLookupController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $results = $q === '' ? [] : ProductSerial::where('imei', 'like', "%{$q}%")
            ->with(['product', 'saleItem.sale.customer'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ProductSerial $s) => [
                'id' => $s->id,
                'imei' => $s->imei,
                'status' => $s->status,
                'product' => $s->product?->name,
                'warranty_expiry' => $s->warranty_expiry?->toDateString(),
                'under_warranty' => $s->isUnderWarranty(),
                'sale_invoice' => $s->saleItem?->sale?->invoice_no,
                'sale_date' => $s->saleItem?->sale?->date?->toDateString(),
                'customer' => $s->saleItem?->sale?->customer?->name,
                'customer_phone' => $s->saleItem?->sale?->customer?->phone,
            ]);

        return Inertia::render('App/Serials/Index', [
            'q' => $q,
            'results' => $results,
        ]);
    }
}
