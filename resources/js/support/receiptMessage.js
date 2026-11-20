// Builds the exact WhatsApp message text for a sale-shaped object (a real
// synced Sale from the server, or an offline-pending snapshot — see
// offlineSaleSnapshot.js). Extracted here (Phase 12, docs/FIX_PLAN.md) so
// Pos/Index.vue, Clothing/Pos.vue, and AppLayout.vue's pending-sales print
// preview all send byte-identical messages instead of three separately-
// maintained near-copies that could silently drift apart.
//
// Khaled's explicit ask: the memo is ALWAYS English, regardless of the
// shop's own UI language — so this never calls the passed-in `t()` for
// memo content (kept as a parameter only so existing call sites don't
// need updating; it's simply unused now).
export function buildReceiptWhatsAppText(shop, sale) {
    const settings = shop?.memo_settings || {};
    const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
    const shopName = shop?.name_en || shop?.name || 'Zaylotix POS';

    const headerLines = [`*${shopName}*`];
    if (shop?.phone) headerLines.push(`📞 ${shop.phone}${shop?.area ? ' • ' + shop.area : ''}`);
    if (settings.show_email && shop?.email) headerLines.push(`✉ ${shop.email}`);
    if (settings.show_website && shop?.website) headerLines.push(`🌐 ${shop.website}`);
    if (settings.show_bin && shop?.bin_no && sale.vat > 0) headerLines.push(`BIN: ${shop.bin_no}`);
    if (settings.show_trade_license && shop?.trade_license_no) headerLines.push(`Trade License: ${shop.trade_license_no}`);
    if (settings.show_tin && shop?.tin_no) headerLines.push(`TIN: ${shop.tin_no}`);
    if (settings.show_cashier && sale.user?.name) headerLines.push(`Served by: ${sale.user.name}`);

    const customerLine = `Customer: ${sale.customer?.name || 'Walk-in customer'}${settings.show_customer_phone && sale.customer?.phone ? ' • ' + sale.customer.phone : ''}`;

    const lines = sale.items.map((l) => `${l.product_name}${(l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : ''}  ${l.qty}×${l.price} = ${money(l.price * l.qty - (l.discount || 0))}${l.discount > 0 ? ' (Discount −' + money(l.discount) + ')' : ''}`).join('\n');

    const totals = [`Subtotal: ${money(sale.subtotal)}`];
    if (sale.discount > 0) totals.push(`Discount: − ${money(sale.discount)}`);
    if (sale.service_charge > 0) totals.push(`Service Charge: + ${money(sale.service_charge)}`);
    if (sale.vat > 0) totals.push(`VAT${settings.show_vat_inclusive_note ? ' (included in price)' : ''}: ${money(sale.vat)}`);
    totals.push(`*Total: ${money(sale.total)}*`);

    const tendered = (sale.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
    const change = Math.max(0, tendered - Number(sale.total));
    const due = Math.max(0, Number(sale.total) - tendered);
    if (settings.show_payment_method) totals.push(`Payment: ${sale.payment_mode}`);
    if (settings.show_tendered_change && tendered > 0) totals.push(`Tendered: ${money(tendered)}`);
    if (settings.show_tendered_change && change > 0) totals.push(`Change: ${money(change)}`);
    if (due > 0) totals.push(`Due balance: ${money(due)}`);

    const footerLines = [shop?.receipt_footer || 'Thank you! Please come again'];
    if (settings.show_exchange_policy && settings.exchange_policy_text) footerLines.push(settings.exchange_policy_text);

    const divider = '─'.repeat(16);
    const pendingNote = sale.isOfflinePending ? ['⏳ This is an offline bill — final number and totals after sync.', divider] : [];

    return [...headerLines, divider, `Memo: ${sale.invoice_no} • ${sale.date} ${sale.time}`, ...pendingNote, customerLine, divider, lines, divider, ...totals, '', ...footerLines].join('\n');
}

export function whatsAppUrlFor(sale, text) {
    const phone = sale.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    return 'https://wa.me/' + num + '?text=' + encodeURIComponent(text);
}
