// Phase 39 (docs/FIX_PLAN.md) — what voiding this sale WILL reverse, for
// the confirmation modal's summary only. Mirrors app/Support/SaleReversal
// display-for-display; the actual reversal is always computed server-side —
// nothing returned here is ever sent back or trusted.
export function buildVoidPreview(sale, collectedTotal = 0, returned = {}) {
    // What a return/exchange against this bill has already given back.
    // SaleReversal credits only the remainder, so the preview has to show
    // the remainder too — promising the full invoice back and then moving
    // less would read as a bug to whoever is confirming the void.
    const returnedRefund = Number(returned.refund || 0);
    const returnedQtyByItem = returned.qtyByItem || {};

    const items = (sale.items || [])
        .map((item) => ({
            name: item.product_name,
            variantLabel: item.variant_label || item.unit_label || null,
            qty: Number(item.qty) - Number(returnedQtyByItem[item.id] ?? 0),
        }))
        // a line that came back in full is already settled — the void
        // doesn't touch its stock at all, so it isn't listed
        .filter((line) => line.qty > 0.0001);

    let cashBack = 0;
    let bankBack = 0;
    const payments = sale.payments || [];

    if (payments.length) {
        for (const p of payments) {
            const amount = Number(p.amount);
            if (p.method === 'cash') cashBack += amount;
            else if (p.method !== 'cod') bankBack += amount;
        }
    } else if (sale.payment_mode === 'cash') {
        cashBack = Number(sale.total);
    } else if (sale.payment_mode === 'bkash' || sale.payment_mode === 'nagad') {
        bankBack = Number(sale.total);
    }

    const tendered = payments.reduce((sum, p) => sum + Number(p.amount), 0);
    const dueRemainder = payments.length
        ? Math.max(0, Number(sale.total) - tendered)
        : (sale.payment_mode === 'credit' ? Number(sale.total) : 0);

    // the already-paid refund splits across due/cash the same proportional
    // way SaleReversal::splitRefund does
    const total = Number(sale.total) || 0;
    const refundDue = returnedRefund > 0 && total > 0 && dueRemainder > 0
        ? Math.min(dueRemainder, Math.round((returnedRefund * dueRemainder / total) * 100) / 100)
        : 0;
    const refundCash = returnedRefund > 0 ? returnedRefund - refundDue : 0;
    const dueOutstanding = Math.max(0, dueRemainder - refundDue);

    cashBack = Math.max(0, cashBack - refundCash);

    // part of the due already collected becomes the customer's advance
    // (store credit) — see SaleReversal's collected→advance handling
    const advanceCredit = Math.min(Number(collectedTotal) || 0, dueOutstanding);
    const dueReduction = dueOutstanding - advanceCredit;

    // positive = customer gets points back (they redeemed more than they
    // earned on this bill), negative = points taken away
    const pointsDelta = Number(sale.points_redeemed || 0) - Number(sale.points_earned || 0);

    return { items, cashBack, bankBack, dueReduction, advanceCredit, pointsDelta, returnedRefund };
}
