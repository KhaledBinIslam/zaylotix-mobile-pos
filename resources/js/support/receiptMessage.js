// Builds the exact WhatsApp message text for a sale-shaped object (a real
// synced Sale from the server, or an offline-pending snapshot — see
// offlineSaleSnapshot.js). Extracted here (Phase 12, docs/FIX_PLAN.md) so
// Pos/Index.vue, Clothing/Pos.vue, and AppLayout.vue's pending-sales print
// preview all send byte-identical messages instead of three separately-
// maintained near-copies that could silently drift apart.
export function buildReceiptWhatsAppText(shop, sale, t) {
    const settings = shop?.memo_settings || {};
    const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

    const headerLines = [`*${shop?.name || 'Zaylotix POS'}*`];
    if (shop?.phone) headerLines.push(`📞 ${shop.phone}${shop?.area ? ' • ' + shop.area : ''}`);
    if (settings.show_email && shop?.email) headerLines.push(`✉️ ${shop.email}`);
    if (settings.show_website && shop?.website) headerLines.push(`🌐 ${shop.website}`);
    if (settings.show_bin && shop?.bin_no && sale.vat > 0) headerLines.push(`BIN: ${shop.bin_no}`);
    if (settings.show_trade_license && shop?.trade_license_no) headerLines.push(`Trade License: ${shop.trade_license_no}`);
    if (settings.show_tin && shop?.tin_no) headerLines.push(`TIN: ${shop.tin_no}`);
    if (settings.show_cashier && sale.user?.name) headerLines.push(`বিক্রেতা: ${sale.user.name}`);

    const customerLine = `কাস্টমার: ${sale.customer?.name || 'নগদ কাস্টমার'}${settings.show_customer_phone && sale.customer?.phone ? ' • ' + sale.customer.phone : ''}`;

    const lines = sale.items.map((l) => `${l.product_name}${(l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : ''}  ${l.qty}×${l.price} = ${money(l.price * l.qty - (l.discount || 0))}${l.discount > 0 ? ' (ছাড় −' + money(l.discount) + ')' : ''}`).join('\n');

    const totals = [`Subtotal: ${money(sale.subtotal)}`];
    if (sale.discount > 0) totals.push(`ছাড়: − ${money(sale.discount)}`);
    if (sale.service_charge > 0) totals.push(`${t('pos.serviceCharge')}: + ${money(sale.service_charge)}`);
    if (sale.vat > 0) totals.push(`VAT${settings.show_vat_inclusive_note ? ' (দামের মধ্যে অন্তর্ভুক্ত)' : ''}: ${money(sale.vat)}`);
    totals.push(`*মোট: ${money(sale.total)}*`);

    const tendered = (sale.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
    const change = Math.max(0, tendered - Number(sale.total));
    const due = Math.max(0, Number(sale.total) - tendered);
    if (settings.show_payment_method) totals.push(`পেমেন্ট: ${sale.payment_mode}`);
    if (settings.show_tendered_change && tendered > 0) totals.push(`দিয়েছেন: ${money(tendered)}`);
    if (settings.show_tendered_change && change > 0) totals.push(`ফেরত: ${money(change)}`);
    if (due > 0) totals.push(`বাকি বিল: ${money(due)}`);

    const footerLines = [shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏'];
    if (settings.show_exchange_policy && settings.exchange_policy_text) footerLines.push(settings.exchange_policy_text);

    const divider = '─'.repeat(16);
    const pendingNote = sale.isOfflinePending ? ['⏳ এটি একটি অফলাইন বিল — সিঙ্কের পর চূড়ান্ত নম্বর ও হিসাব পাবেন।', divider] : [];

    return [...headerLines, divider, `মেমো: ${sale.invoice_no}`, ...pendingNote, customerLine, divider, lines, divider, ...totals, '', ...footerLines].join('\n');
}

export function whatsAppUrlFor(sale, text) {
    const phone = sale.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    return 'https://wa.me/' + num + '?text=' + encodeURIComponent(text);
}
