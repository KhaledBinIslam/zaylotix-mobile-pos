<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `shop` + `subscription` middleware on the route
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            // a line is either a pack-unit conversion or a size/color
            // variant, never both — enforced in PosController::checkout
            // since a wildcard-to-wildcard wouldn't be expressible cleanly
            // as a single declarative rule here
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // optional — the specific unit's IMEI, typed/scanned at
            // checkout; resolved (not trusted) server-side against that
            // exact product's in-stock serials
            'items.*.imei' => ['nullable', 'string', 'max:100'],
            // whole numbers for a regular/pack-size/variant line, but a
            // weighed base-unit line (no product_unit_id/product_variant_id)
            // may be a fraction of a kg/litre — PosController::checkout
            // enforces the whole-number rule per-line since it needs the
            // product's own sold_by_weight flag to know which applies
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            // Zero or more actual tender lines — e.g. [{method:cash,amount:300},
            // {method:bkash,amount:200}]. Whatever the total isn't covered by
            // these becomes the attached customer's due; an empty/absent
            // array means the whole sale goes on credit. The old single
            // `payment_mode` string is no longer accepted from the client —
            // the server derives the display value from what was tendered.
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', 'in:cash,bkash,nagad'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            // free-text prescription reference (doctor, medicine, dosage
            // note) — only meaningful for pharmacy-type shops with the
            // prescription_records feature, but harmless to accept from
            // anyone since it's just stored as-is on the sale
            'prescription_note' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            // whole-sale toggle — every line in this cart prices at the
            // product's wholesale_price when set to 'wholesale' (falling
            // back to the normal price for any line whose product has no
            // wholesale_price of its own), never a per-line choice
            'sale_type' => ['nullable', 'in:retail,wholesale'],
        ];
    }
}
