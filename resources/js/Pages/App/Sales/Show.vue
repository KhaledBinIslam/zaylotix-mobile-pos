<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

const props = defineProps({ sale: Object });
const { toast } = useToast();

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

function sendMemoWA() {
    const s = props.sale;
    const phone = s.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    const lines = s.items.map((l) => `${l.product_name}${(l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : ''}  ${l.qty}×${l.price} = ${money(l.price * l.qty)}`).join('\n');
    const text = `*${shop.value?.name || 'Zaylotix POS'}*\n${'─'.repeat(16)}\nমেমো: ${s.invoice_no}\n${'─'.repeat(16)}\n${lines}\n${'─'.repeat(16)}\n*মোট: ${money(s.total)}*\n\nধন্যবাদ 🙏`;
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

// CODE128 barcode of this invoice — scanning it (with the same
// hardware/camera scanner already wired into the POS screen) re-opens this
// exact page. "INV-" prefixes the id so the POS scan handler can tell this
// apart from a product barcode before falling back to a product lookup.
onMounted(async () => {
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById('sale-barcode-svg');
    if (el) {
        try {
            JsBarcode(el, 'INV-' + props.sale.id, { format: 'CODE128', width: 1.3, height: 30, fontSize: 9, margin: 2 });
        } catch (e) { /* shouldn't happen — id is always numeric */ }
    }

    // QR code linking to the public (no-login) rating page for this sale —
    // a customer scanning this from the paper receipt has no Zaylotix
    // account, so the target route must not require auth
    const QRCode = (await import('qrcode')).default;
    const qrEl = document.getElementById('sale-rating-qr');
    if (qrEl) {
        try {
            await QRCode.toCanvas(qrEl, ratingUrl.value, { width: 90, margin: 1 });
        } catch (e) { /* non-critical — receipt still works without it */ }
    }

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
const ratingUrl = computed(() => window.location.origin + route('rate.show', props.sale.id));
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
        <div class="print-order-wrap">
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

        <div id="printable-memo" class="receipt" :style="memoStyle">
            <img v-if="shop?.logo_url" :src="shop.logo_url" style="max-width:120px;display:block;margin:0 auto 8px">
            <h3>{{ shop?.name || 'Zaylotix POS' }}</h3>
            <div class="rc-sub">
                📞 {{ shop?.phone }} • {{ shop?.area }}
                <template v-if="shop?.bin_no && sale.vat > 0"><br>BIN: {{ shop.bin_no }}</template>
                <br>মেমো — {{ sale.invoice_no }} • {{ sale.date }} {{ sale.time }}
                <template v-if="tableOrder">
                    <br>{{ t('restaurant.orderNo', { id: tableOrder.id }) }} • {{ tableOrder.order_source === 'delivery' ? t('restaurant.sourceDelivery') : tableOrder.order_source === 'takeaway' ? t('restaurant.sourceTakeaway') : t('restaurant.sourceDineIn') }}{{ tableOrder.table?.name ? ' — ' + tableOrder.table.name : '' }}
                    <template v-if="tableOrder.waiter_name"> • {{ tableOrder.waiter_name }}</template>
                </template>
            </div>
            <div style="font-size:11px;color:#666;margin-bottom:6px">কাস্টমার: {{ sale.customer?.name || 'নগদ কাস্টমার' }}</div>
            <div v-for="l in sale.items" :key="l.id" class="rc-l">
                <span>{{ l.product_name }}{{ (l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : '' }} ×{{ l.qty }}</span><b>{{ money(l.price * l.qty) }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:10.5px;color:#555;border-top:1px dashed #999;margin-top:4px;padding-top:4px"><span>Subtotal</span><span>{{ money(sale.subtotal) }}</span></div>
            <div v-if="sale.discount > 0" class="rc-l"><span>ছাড়{{ sale.coupon_code ? ` (${sale.coupon_code})` : '' }}</span><b>− {{ money(sale.discount) }}</b></div>
            <div v-if="sale.service_charge > 0" class="rc-l"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(sale.service_charge) }}</b></div>
            <div v-if="sale.vat > 0" class="rc-l"><span>VAT</span><b>{{ money(sale.vat) }}</b></div>
            <div class="rc-t"><span>মোট</span><span>{{ money(sale.total) }}</span></div>
            <div v-if="sale.points_redeemed || sale.points_earned" style="text-align:center;color:#555;margin-top:6px;font-size:10.5px">
                <span v-if="sale.points_redeemed">{{ t('pos.pointsRedeemedReceipt', { n: sale.points_redeemed }) }} </span>
                <span v-if="sale.points_earned">{{ t('pos.pointsEarnedReceipt', { n: sale.points_earned }) }}</span>
            </div>
            <div style="text-align:center;color:#c0392b;font-weight:700;margin-top:8px;font-size:12px" v-if="dueRemainder > 0">বাকি বিল — {{ money(dueRemainder) }}</div>
            <div class="rc-f">{{ shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏' }}</div>
            <div style="display:flex;justify-content:center;margin-top:8px"><svg id="sale-barcode-svg"></svg></div>
            <div style="display:flex;flex-direction:column;align-items:center;margin-top:6px;gap:2px">
                <canvas id="sale-rating-qr"></canvas>
                <div style="font-size:9px;color:#666">{{ t('sales.rateHint') }}</div>
            </div>
            <div class="rc-brand">
                <template v-if="platformLogoUrl"><img :src="platformLogoUrl" alt="Zaylotix"></template>
                <template v-else>A Zaylotix product · zaylotix.com</template>
            </div>
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
@media print {
    @page { size: 58mm auto; margin: 0; }
    /* stacks both copies in a single column ordered by the `order` style
       set above (kotRecapStyle/memoStyle) — flex order affects print
       pagination the same way it affects visual layout, so this is how the
       owner's kitchen_print_order preference actually reorders which copy
       comes off the printer first, without duplicating markup */
    .print-order-wrap { display: flex; flex-direction: column; }
    #printable-memo { width: 58mm; padding: 2mm 3mm; border-radius: 0; font-size: 11px; }
    #printable-memo h3 { font-size: 13px; }
    #printable-kot-recap {
        width: 58mm; padding: 2mm 3mm; font-family: monospace; font-size: 12px;
    }
    #printable-kot-recap h3 { font-size: 14px; margin: 0 0 4px; text-align: center; }
    #printable-kot-recap .rc-sub { font-size: 10px; text-align: center; margin-bottom: 6px; }
    #printable-kot-recap .rc-l { display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px dashed #999; }
    #printable-kot-recap .rc-note { margin-top: 6px; padding-top: 4px; border-top: 1px dashed #999; font-weight: bold; white-space: pre-wrap; }
}
#printable-kot-recap { display: none; }
@media print { #printable-kot-recap { display: block; } }
</style>
