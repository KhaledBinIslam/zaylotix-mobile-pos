// Builds a Sale-shaped object straight from an offline-queued checkout
// payload, good enough to feed into <ReceiptMemo> for printing/WhatsApp
// BEFORE the sale has ever reached the server — see docs/FIX_PLAN.md.
// Pure function (payload + the shop object, both already available
// client-side — no server round-trip) so it works identically whether
// called right after queueing (Pos/Index.vue) or later from the pending-
// sales sheet (AppLayout.vue) for any still-unsynced entry.
//
// Deliberately approximate where the real figure can only ever be known
// server-side (coupon-resolved discount, VAT, profit, loyalty points) —
// isOfflinePending:true is the signal ReceiptMemo.vue/the WhatsApp text
// use to clearly label the receipt as provisional rather than silently
// presenting an approximation as the final bill.
export function buildOfflineSaleSnapshot(payload) {
    const items = (payload.items || []).map((item, idx) => ({
        id: idx,
        product_name: item.product_name || '',
        unit_label: item.unit_label || null,
        variant_label: item.variant_label || null,
        qty: item.qty,
        price: item.unit_price ?? 0,
        discount: item.discount || 0,
    }));

    const grossSubtotal = items.reduce((sum, i) => sum + i.price * i.qty, 0);
    const lineDiscounts = items.reduce((sum, i) => sum + i.discount, 0);
    const subtotal = Math.max(0, grossSubtotal - lineDiscounts);
    // Coupon/loyalty-point discounts aren't resolvable client-side (they
    // need a server lookup) — only the manual discount the cashier typed
    // is reflected here; real checkout's actual discount may be larger.
    const discount = payload.complimentary ? subtotal : Math.min(payload.discount || 0, subtotal);
    const afterDiscount = Math.max(0, subtotal - discount);

    const payments = payload.payments || [];
    let paymentMode = 'cash';
    if (!payments.length) {
        paymentMode = payload.complimentary ? 'cash' : 'credit';
    } else if (payments.length > 1) {
        paymentMode = 'split';
    } else {
        paymentMode = payments[0].method;
    }

    const now = new Date();

    return {
        id: null,
        // A short, locally-unique placeholder — client_uuid is already
        // this sale's real idempotency key, so reusing it (not a random
        // separate number) means the same offline sale always shows the
        // same placeholder, even if this snapshot is rebuilt later from
        // the stored queue entry.
        invoice_no: 'OFF-' + (payload.client_uuid || '').replace(/-/g, '').slice(0, 8).toUpperCase(),
        date: now.toISOString().slice(0, 10),
        time: now.toTimeString().slice(0, 8),
        items,
        subtotal,
        discount,
        coupon_code: payload.coupon_code || null,
        // Service charge/VAT need the shop's own rate config to compute
        // correctly and aren't critical to an approximate preview receipt
        // — left at 0 here rather than guessed, same spirit as leaving
        // profit/loyalty points off entirely (ReceiptMemo already treats
        // all of these as optional, v-if-gated fields).
        service_charge: 0,
        vat: 0,
        total: afterDiscount,
        payments,
        payment_mode: paymentMode,
        customer: payload.customer_phone ? { name: payload.customer_name || '', phone: payload.customer_phone } : null,
        user: null,
        points_redeemed: null,
        points_earned: null,
        isOfflinePending: true,
    };
}
