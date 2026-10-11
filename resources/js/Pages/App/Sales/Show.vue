<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReceiptMemo from '@/Components/ReceiptMemo.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({ sale: Object });
const { toast } = useToast();
const { hasPerm } = usePermissions();

const page = usePage();
const shop = computed(() => page.props.shop);
const features = computed(() => page.props.features || []);
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const user = computed(() => page.props.auth?.user);
const isOwner = computed(() => user.value?.role === 'owner');
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const payModeLabel = computed(() => ({ cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split') }));

const dueRemainder = computed(() => {
    const tendered = (props.sale.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
    return Math.max(0, Number(props.sale.total) - tendered);
});

function voidSale() {
    const reason = prompt(t('sales.voidReasonPrompt'));
    if (reason === null) return;
    router.delete(route('app.sales.destroy', props.sale.id), {
        data: { reason },
        onSuccess: () => router.visit(route('app.sales')),
    });
}

// Mirrors ReceiptMemo.vue's printed memo field-for-field (same
// memo_settings toggles) — previously this built its own much shorter
// text (shop name, items, total only), so a customer's WhatsApp copy and
// their printed copy showed different information.
function sendMemoWA() {
    const s = props.sale;
    const settings = shop.value?.memo_settings || {};
    const phone = s.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';

    const headerLines = [`*${shop.value?.name || 'Zaylotix POS'}*`];
    if (shop.value?.phone) headerLines.push(`📞 ${shop.value.phone}${shop.value?.area ? ' • ' + shop.value.area : ''}`);
    if (settings.show_email && shop.value?.email) headerLines.push(`✉️ ${shop.value.email}`);
    if (settings.show_website && shop.value?.website) headerLines.push(`🌐 ${shop.value.website}`);
    if (settings.show_bin && shop.value?.bin_no && s.vat > 0) headerLines.push(`BIN: ${shop.value.bin_no}`);
    if (settings.show_trade_license && shop.value?.trade_license_no) headerLines.push(`Trade License: ${shop.value.trade_license_no}`);
    if (settings.show_tin && shop.value?.tin_no) headerLines.push(`TIN: ${shop.value.tin_no}`);
    if (settings.show_cashier && s.user?.name) headerLines.push(`বিক্রেতা: ${s.user.name}`);

    const customerLine = `কাস্টমার: ${s.customer?.name || 'নগদ কাস্টমার'}${settings.show_customer_phone && s.customer?.phone ? ' • ' + s.customer.phone : ''}`;

    const lines = s.items.map((l) => `${l.product_name}${(l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : ''}  ${l.qty}×${l.price} = ${money(l.price * l.qty - (l.discount || 0))}${l.discount > 0 ? ' (ছাড় −' + money(l.discount) + ')' : ''}`).join('\n');

    const totals = [`Subtotal: ${money(s.subtotal)}`];
    if (s.discount > 0) totals.push(`ছাড়: − ${money(s.discount)}`);
    if (s.service_charge > 0) totals.push(`${t('pos.serviceCharge')}: + ${money(s.service_charge)}`);
    if (s.vat > 0) totals.push(`VAT${settings.show_vat_inclusive_note ? ' (দামের মধ্যে অন্তর্ভুক্ত)' : ''}: ${money(s.vat)}`);
    totals.push(`*মোট: ${money(s.total)}*`);

    const tendered = (s.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
    const change = Math.max(0, tendered - Number(s.total));
    const due = dueRemainder.value;
    if (settings.show_payment_method) totals.push(`পেমেন্ট: ${payModeLabel.value[s.payment_mode]}`);
    if (settings.show_tendered_change && tendered > 0) totals.push(`দিয়েছেন: ${money(tendered)}`);
    if (settings.show_tendered_change && change > 0) totals.push(`ফেরত: ${money(change)}`);
    if (due > 0) totals.push(`বাকি বিল: ${money(due)}`);

    const footerLines = [shop.value?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏'];
    if (settings.show_exchange_policy && settings.exchange_policy_text) footerLines.push(settings.exchange_policy_text);

    const divider = '─'.repeat(16);
    const text = [
        ...headerLines, divider, `মেমো: ${s.invoice_no} • ${s.date} ${s.time}`, customerLine, divider,
        lines, divider, ...totals, '', ...footerLines,
    ].join('\n');

    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
}

// only present when this sale came from billing a restaurant table order —
// the single print/WhatsApp buttons below quietly cover both the kitchen
// ticket and the customer memo for these, no separate action needed
const tableOrder = computed(() => props.sale.table_order);
// owner-configurable which copy comes off the printer first when both print
// together — see the migration's comment on shops.kitchen_print_order
const kotFirst = computed(() => (shop.value?.kitchen_print_order || 'kitchen_first') !== 'customer_first');
// page-break-after belongs on whichever copy prints first, not fixed to one element — the second/last copy must not carry a trailing page break
const kotRecapStyle = computed(() => ({ order: kotFirst.value ? 1 : 2, pageBreakAfter: kotFirst.value ? 'always' : 'auto' }));
const memoStyle = computed(() => (tableOrder.value ? { order: kotFirst.value ? 2 : 1, pageBreakAfter: kotFirst.value ? 'auto' : 'always' } : {}));
const kitchenWaNumber = computed(() => shop.value?.kitchen_whatsapp || '');
const paperClass = computed(() => 'paper-' + (shop.value?.memo_settings?.paper_width || '58mm').replace('mm', ''));
function sendKotWA() {
    const num = '88' + kitchenWaNumber.value.replace(/\D/g, '').replace(/^88/, '');
    const lines = props.sale.items.map((l) => `${l.product_name}  ×${l.qty}`).join('\n');
    const order = tableOrder.value;
    const sourceLine = order.order_source === 'delivery'
        ? `\n🛵 ${t('restaurant.sourceDelivery')} — ${order.delivery_platform || ''}`
        : order.order_source === 'takeaway' ? `\n🥡 ${t('restaurant.sourceTakeaway')}` : '';
    const noteLine = order.kitchen_note ? `\n📝 ${order.kitchen_note}` : '';
    const text = `*${t('restaurant.kotTitle')}*\n${'─'.repeat(16)}\n${order.table?.name || ''}${sourceLine}\n${'─'.repeat(16)}\n${lines}${noteLine}`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
}

function printMemo() {
    window.print();
}

// invoice barcode + rating QR are rendered by ReceiptMemo.vue itself now
onMounted(async () => {
    // ?autoprint=1 — see TableOrderController::bill()'s comment. An earlier
    // version of this actually called window.print() automatically here,
    // via a fixed setTimeout delay, but that produced a BLANK printed page
    // on real testing (reported live) instead of just "not printing" —
    // billing is a real server round trip landing on a brand-new page load,
    // not a direct click, and window.print() fired that way can capture
    // the page before the print stylesheet/layout has actually settled,
    // with no reliable way to know when it's "safe" from here. Rather than
    // keep guessing at a timing fix for something inherently unreliable,
    // this now just makes the ALREADY-WORKING manual print button
    // impossible to miss instead — a toast pointing at it, real user
    // click, same synchronous-on-click pattern every other working print
    // button in this app already uses (printKot, printMemo).
    if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
        history.replaceState(null, '', window.location.pathname);
        justBilled.value = true;
        toast('✅ ' + t('sales.justBilledPrintHint'));
    }
});
const justBilled = ref(false);
</script>

<template>
    <Head :title="sale.invoice_no" />
    <AppLayout active="more">
        <div class="no-print pgttl">{{ sale.invoice_no }}</div>
        <div class="no-print pgsub">{{ sale.date }} • {{ sale.time }}{{ sale.user ? ' • ' + t('sales.seller') + ' ' + sale.user.name : '' }}</div>

        <div v-if="sale.voided_at" class="no-print card" style="margin-bottom:12px;background:var(--roseSoft);border-color:var(--rose)">
            <div style="font-weight:800;color:var(--rose)">🚫 {{ t('sales.voided') }}</div>
            <div style="font-size:13px;color:var(--mut);margin-top:4px">{{ t('sales.voidedBy') }}: {{ sale.voided_by_user?.name || '—' }}</div>
            <div style="font-size:13px;color:var(--mut)">{{ t('sales.voidReason') }}: {{ sale.voided_reason }}</div>
        </div>

        <!-- kitchen ticket + customer memo — only for a sale that came from billing a restaurant table order; printing this page prints both in one go. Which one physically comes off the printer first is owner-configurable (kotFirst, from shops.kitchen_print_order) — done via flex `order` rather than swapping DOM position, since print pagination follows visual/flex order for a single stacked column. -->
        <div class="print-order-wrap" :class="paperClass">
        <div v-if="tableOrder" id="printable-kot-recap" :style="kotRecapStyle">
            <h3>{{ t('restaurant.kotTitle') }}</h3>
            <div class="rc-sub">
                {{ t('restaurant.orderNo', { id: tableOrder.id }) }}<br>
                {{ tableOrder.table?.name }}
                <template v-if="tableOrder.order_source === 'delivery'"><br>🛵 {{ t('restaurant.sourceDelivery') }} — {{ tableOrder.delivery_platform }}</template>
                <template v-else-if="tableOrder.order_source === 'takeaway'"><br>🥡 {{ t('restaurant.sourceTakeaway') }}</template>
            </div>
            <div v-for="l in sale.items" :key="l.id" class="rc-l">
                <span>{{ l.product_name }}</span><b>×{{ l.qty }}</b>
            </div>
            <div v-if="tableOrder.kitchen_note" class="rc-note">📝 {{ tableOrder.kitchen_note }}</div>
        </div>

        <!-- no .receipt class here — ReceiptMemo.vue is fully self-styled
             now; the global .receipt/.rc-l/.rc-t/etc rules in app.css would
             otherwise collide with its own same-named sub-elements -->
        <div id="printable-memo" :style="memoStyle">
            <ReceiptMemo :shop="shop" :sale="sale" :platform-logo-url="platformLogoUrl" />
        </div>
        </div>

        <!-- shown right after billing (?autoprint=1) — a real click here is
             the reliable way to print (see the onMounted comment above);
             this + the toast are how that gets impossible to miss instead -->
        <div v-if="justBilled" class="no-print card" style="margin-top:16px;background:var(--goldSoft);border-color:var(--gold2);text-align:center;font-weight:700;color:var(--gold2)">
            ✅ {{ t('sales.justBilledPrintHint') }}
        </div>
        <div class="no-print btnrow" style="margin-top:16px">
            <button v-if="features.includes('memo_whatsapp')" class="btn wa" style="flex:1" @click="sendMemoWA">📤 WhatsApp</button>
            <button v-if="features.includes('memo_print')" :class="justBilled ? 'btn' : 'btn ghost'" style="flex:1" @click="printMemo">🖨️ {{ t('pos.print') }}{{ tableOrder ? ' (' + t('restaurant.kotTitle') + ' + ' + t('sales.memoLabel') + ')' : '' }}</button>
        </div>
        <button v-if="tableOrder && kitchenWaNumber" class="no-print btn wa" style="margin-top:10px" @click="sendKotWA">📤 {{ t('restaurant.kotWa') }}</button>

        <div class="no-print card" style="margin-top:16px">
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('sales.paymentMethod') }}</span><b>{{ payModeLabel[sale.payment_mode] }}</b></div>
            <div v-if="sale.payments?.length" style="margin:4px 0 2px;padding-top:4px;border-top:1px dashed var(--line)">
                <div style="font-size:12px;color:var(--mut);margin-bottom:2px">{{ t('sales.paymentBreakdown') }}</div>
                <div v-for="p in sale.payments" :key="p.id" style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px">
                    <span>{{ payModeLabel[p.method] }}</span><b>{{ money(p.amount) }}</b>
                </div>
                <div v-if="dueRemainder > 0" style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px;color:var(--rose)">
                    <span>{{ t('sales.dueRemainder') }}</span><b>{{ money(dueRemainder) }}</b>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('sales.subtotal') }}</span><b>{{ money(sale.subtotal) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('sales.profit') }}</span><b style="color:var(--green)">{{ money(sale.profit) }}</b></div>
        </div>

        <div v-if="sale.prescription_note" class="no-print card" style="margin-top:16px;border-color:var(--rose)">
            <div style="font-size:12px;color:var(--rose);font-weight:700;margin-bottom:4px">℞ {{ t('sales.prescriptionNoteLabel') }}</div>
            <div style="font-size:13px;white-space:pre-wrap">{{ sale.prescription_note }}</div>
        </div>

        <Link v-if="hasPerm('returns') && features.includes('returns') && !sale.voided_at" :href="route('app.returns.index', { sale_id: sale.id })" class="no-print btn ghost" style="margin-top:16px;display:block;text-align:center">
            ↩️ {{ t('sales.returnExchangeButton') }}
        </Link>

        <button v-if="isOwner && !sale.voided_at" class="no-print btn ghost" style="margin-top:16px;color:var(--rose);border-color:var(--rose)" @click="voidSale">
            {{ t('sales.voidButton') }}
        </button>
    </AppLayout>
</template>

<style>
/* This page's receipt is a normal part of the page (not a modal like the
   POS one), so #printable-memo sits directly inside #app alongside its
   siblings above — those siblings are marked .no-print instead of hiding
   #app wholesale. (The old technique here was `body * { visibility:
   hidden }`, which hides elements visually but leaves them occupying their
   full layout space — every sibling still pushed the receipt around and
   padded the printed page with blank area. See BarcodeLabels/Index.vue and
   Pos/Index.vue for the two other places this exact mistake was made.) */
/* Named @page rules (Chromium-supported — every POS/kiosk browser this app
   targets is Chromium) so the actual paper size follows the shop's own
   memo_settings.paper_width choice instead of one fixed 58mm assumption. */
@page thermal58 { size: 58mm auto; margin: 0; }
@page thermal80 { size: 80mm auto; margin: 0; }
@page a4memo { size: A4; margin: 10mm; }

@media print {
    /* stacks both copies in a single column ordered by the `order` style
       set above (kotRecapStyle/memoStyle) — flex order affects print
       pagination the same way it affects visual layout, so this is how the
       owner's kitchen_print_order preference actually reorders which copy
       comes off the printer first, without duplicating markup */
    .print-order-wrap { display: flex; flex-direction: column; }

    /* strip the on-screen preview chrome (fixed 280px width, border, grey
       background — see ReceiptMemo.vue's own default style) so the printed
       copy fills the real paper width instead */
    #printable-memo .receipt-memo { width: auto !important; margin: 0 !important; border: none !important; }

    .paper-58 { page: thermal58; }
    .paper-58 #printable-memo, .paper-58 #printable-kot-recap { width: 58mm; }
    .paper-80 { page: thermal80; }
    .paper-80 #printable-memo, .paper-80 #printable-kot-recap { width: 80mm; }
    .paper-a4 { page: a4memo; }
    .paper-a4 #printable-memo, .paper-a4 #printable-kot-recap { width: 180mm; }

    #printable-memo { padding: 2mm 3mm; border-radius: 0; font-size: 11px; }
    #printable-memo h3 { font-size: 13px; }
    #printable-kot-recap {
        padding: 2mm 3mm; font-family: monospace; font-size: 12px;
    }
    #printable-kot-recap h3 { font-size: 14px; margin: 0 0 4px; text-align: center; }
    #printable-kot-recap .rc-sub { font-size: 10px; text-align: center; margin-bottom: 6px; }
    #printable-kot-recap .rc-l { display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px dashed #999; }
    #printable-kot-recap .rc-note { margin-top: 6px; padding-top: 4px; border-top: 1px dashed #999; font-weight: bold; white-space: pre-wrap; }
}
#printable-kot-recap { display: none; }
@media print { #printable-kot-recap { display: block; } }
</style>
