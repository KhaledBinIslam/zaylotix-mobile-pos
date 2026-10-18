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
            // Deliberately no exists:products,id here (removed as part of
            // the offline-sync "product not found" fix, docs/FIX_PLAN.md):
            // that check is table-wide, not tenant-scoped, so it never
            // actually protected against a cross-shop id anyway — it only
            // ever caught "doesn't exist ANYWHERE," which is now handled
            // far better in PosController::performCheckout() (barcode
            // fallback, then a named, individually-resolvable error per
            // item) than a blunt generic validation failure that gave the
            // offline-sync recovery flow nothing to work with.
            'items.*.product_id' => ['required', 'integer'],
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
            // Diagnostic-only snapshot (see docs/FIX_PLAN.md's offline-sync
            // "product not found" fix) — never trusted for pricing/stock,
            // only used (a) to name the specific product in an error
            // message when its id can't be resolved, and (b) as a
            // same-shop fallback lookup by barcode when the id alone can't
            // be found (e.g. a stale offline queue entry). Optional so any
            // already-queued-but-not-yet-synced entry from before this
            // field existed still checks out exactly as before.
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.product_barcode' => ['nullable', 'string', 'max:100'],
            'items.*.unit_label' => ['nullable', 'string', 'max:100'],
            'items.*.variant_label' => ['nullable', 'string', 'max:100'],
            // Which shop this cart was built under — set by the client from
            // its own current session at the moment checkout is attempted
            // (not the server's). Lets checkout() catch an offline sale
            // that's being replayed under a DIFFERENT shop than the one
            // that queued it (classic trigger: an admin reusing one
            // browser across impersonation sessions for different shops)
            // with a clear, specific error instead of a misleading generic
            // "product not found" for every line. Optional for the same
            // backward-compat reason as above.
            'shop_id' => ['nullable', 'integer'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            // Set only by the offline-sync replay path (useOfflineSync.js's
            // trySync()), never by a live checkout — lets performCheckout()
            // allow resolving a soft-deleted product ONLY for a genuine
            // replay of a sale that was rung up before it was deleted,
            // never for a fresh/live attempt to sell something the shop
            // has since discontinued.
            'offline_replay' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            // free/staff-meal sale — no payment collected at all, tracked
            // separately from a manual discount (see Reports::complimentaryReport())
            'complimentary' => ['nullable', 'boolean'],
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
            // client-generated idempotency key — see PosController::performCheckout()'s
            // matching check-or-return. Optional so any older cached frontend
            // build (before this uuid existed) still checks out fine; just
            // without replay protection until it reloads.
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
