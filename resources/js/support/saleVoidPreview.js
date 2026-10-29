// Phase 39 (docs/FIX_PLAN.md) — what voiding this sale WILL reverse, for
// the confirmation modal's summary only. Mirrors app/Support/SaleReversal
// display-for-display; the actual reversal is always computed server-side —
// nothing returned here is ever sent back or trusted.
export function buildVoidPreview(sale, collectedTotal = 0) {
    const items = (sale.items || []).map((item) => ({
        name: item.product_name,
        variantLabel: item.variant_label || item.unit_label || null,
        qty: Number(item.qty),
    }));

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

    // part of the due already collected becomes the customer's advance
    // (store credit) — see SaleReversal's collected→advance handling
    const advanceCredit = Math.min(Number(collectedTotal) || 0, dueRemainder);
    const dueReduction = dueRemainder - advanceCredit;

    // positive = customer gets points back (they redeemed more than they
    // earned on this bill), negative = points taken away
    const pointsDelta = Number(sale.points_redeemed || 0) - Number(sale.points_earned || 0);

    return { items, cashBack, bankBack, dueReduction, advanceCredit, pointsDelta };
}
