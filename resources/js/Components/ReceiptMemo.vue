<script setup>
import { computed, onMounted, watch, nextTick } from 'vue';
import { useI18n } from '@/composables/useI18n';

/**
 * The actual printed/WhatsApp-matching memo markup — used both by
 * Sales/Show.vue (the real, live memo) and Settings/ReceiptLabel.vue (a
 * live preview with sample data), so the two can never visually drift
 * apart. All visibility is driven by shop.memo_settings (already merged
 * with Shop::MEMO_SETTINGS_DEFAULTS server-side — see Shop::toArrayForUser()).
 */
const props = defineProps({
    shop: { type: Object, required: true },
    sale: { type: Object, required: true },
    platformLogoUrl: { type: String, default: null },
    idSuffix: { type: String, default: '' }, // keeps DOM ids unique when more than one instance renders at once (e.g. the settings preview)
    showBarcodeAndQr: { type: Boolean, default: true },
});

const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const settings = computed(() => props.shop?.memo_settings || {});
const tableOrder = computed(() => props.sale.tableOrder || props.sale.table_order);

const dueRemainder = computed(() => {
    const tendered = (props.sale.payments || []).reduce((sum, p) => sum + Number(p.amount), 0);
    return Math.max(0, Number(props.sale.total) - tendered);
});
const tenderedTotal = computed(() => (props.sale.payments || []).reduce((sum, p) => sum + Number(p.amount), 0));
const changeReturned = computed(() => Math.max(0, tenderedTotal.value - Number(props.sale.total)));

const paymentMethodLabel = computed(() => ({
    cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split'),
}[props.sale.payment_mode] || props.sale.payment_mode));

const barcodeId = computed(() => 'memo-barcode-svg' + props.idSuffix);
const qrId = computed(() => 'memo-rating-qr' + props.idSuffix);
const shopQrId = computed(() => 'memo-shop-qr' + props.idSuffix);

// Khaled: a client wanted their shop's own Facebook page as a scannable QR
// on the printed memo — reuses shops.website (already the exact field the
// "ওয়েবসাইট / Facebook পেজ" settings field writes to, typed without a
// protocol, e.g. "facebook.com/babypark"), so no new shop field was needed.
const shopQrUrl = computed(() => {
    const site = props.shop?.website?.trim();
    if (!site) return null;
    return /^https?:\/\//i.test(site) ? site : 'https://' + site;
});

async function renderCodes() {
    if (!props.showBarcodeAndQr) return;
    await nextTick();
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById(barcodeId.value);
    if (el && props.sale.id) {
        try {
            JsBarcode(el, 'INV-' + props.sale.id, { format: 'CODE128', width: 1.3, height: 30, fontSize: 9, margin: 2 });
        } catch (e) { /* non-numeric sample id in preview mode — harmless */ }
    }
    if (props.sale.rating_url || (settings.value.show_shop_qr && shopQrUrl.value)) {
        const QRCode = (await import('qrcode')).default;
        if (props.sale.rating_url) {
            const qrEl = document.getElementById(qrId.value);
            if (qrEl) {
                try { await QRCode.toCanvas(qrEl, props.sale.rating_url, { width: 90, margin: 1 }); } catch (e) { /* non-critical */ }
            }
        }
        if (settings.value.show_shop_qr && shopQrUrl.value) {
            const shopQrEl = document.getElementById(shopQrId.value);
            if (shopQrEl) {
                try { await QRCode.toCanvas(shopQrEl, shopQrUrl.value, { width: 90, margin: 1 }); } catch (e) { /* non-critical */ }
            }
        }
    }
}
onMounted(renderCodes);
watch(() => [props.sale.id, props.sale.invoice_no, settings.value.show_shop_qr, props.shop?.website], renderCodes);
</script>

<template>
    <div class="receipt-memo" :class="['paper-' + (settings.paper_width || '58mm').replace('mm', ''), { 'rm-framed': settings.show_frame }]">
        <img v-if="shop?.logo_url && settings.show_logo !== false" :src="shop.logo_url" class="rm-logo">
        <h3>{{ shop?.name || 'Zaylotix POS' }}</h3>
        <div class="rc-sub">
            📞 {{ shop?.phone }}<template v-if="shop?.area"> • {{ shop.area }}</template>
            <template v-if="settings.show_email && shop?.email"><br>✉️ {{ shop.email }}</template>
            <template v-if="settings.show_website && shop?.website"><br>🌐 {{ shop.website }}</template>
            <template v-if="settings.show_bin && shop?.bin_no && sale.vat > 0"><br>BIN: {{ shop.bin_no }}</template>
            <template v-if="settings.show_trade_license && shop?.trade_license_no"><br>Trade License: {{ shop.trade_license_no }}</template>
            <template v-if="settings.show_tin && shop?.tin_no"><br>TIN: {{ shop.tin_no }}</template>
            <br>মেমো — {{ sale.invoice_no }} • {{ sale.date }} {{ sale.time }}
            <template v-if="sale.isOfflinePending"><br><span class="rm-offline-pending">⏳ অফলাইন বিল — সিঙ্কের পর চূড়ান্ত নম্বর পাবেন</span></template>
            <template v-if="settings.show_cashier && sale.user?.name"><br>বিক্রেতা: {{ sale.user.name }}</template>
            <template v-if="tableOrder">
                <br>{{ t('restaurant.orderNo', { id: tableOrder.id }) }} • {{ tableOrder.order_source === 'delivery' ? t('restaurant.sourceDelivery') : tableOrder.order_source === 'takeaway' ? t('restaurant.sourceTakeaway') : t('restaurant.sourceDineIn') }}{{ tableOrder.table?.name ? ' — ' + tableOrder.table.name : '' }}
                <template v-if="tableOrder.waiter_name"> • {{ tableOrder.waiter_name }}</template>
            </template>
        </div>
        <div class="rm-customer">
            কাস্টমার: {{ sale.customer?.name || 'নগদ কাস্টমার' }}<template v-if="settings.show_customer_phone && sale.customer?.phone"> • {{ sale.customer.phone }}</template>
        </div>
        <div v-for="l in sale.items" :key="l.id" class="rc-l">
            <span>
                {{ l.product_name }}{{ (l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : '' }} ×{{ l.qty }}
                <template v-if="l.discount > 0"><br><span class="rm-item-discount">ছাড় −{{ money(l.discount) }}</span></template>
            </span>
            <b>{{ money(l.price * l.qty - (l.discount || 0)) }}</b>
        </div>
        <div class="rm-subtotal"><span>Subtotal</span><span>{{ money(sale.subtotal) }}</span></div>
        <div v-if="sale.discount > 0" class="rc-l"><span>ছাড়{{ sale.coupon_code ? ` (${sale.coupon_code})` : '' }}</span><b>− {{ money(sale.discount) }}</b></div>
        <div v-if="sale.service_charge > 0" class="rc-l"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(sale.service_charge) }}</b></div>
        <div v-if="sale.vat > 0" class="rc-l"><span>VAT<template v-if="settings.show_vat_inclusive_note"> (দামের মধ্যে অন্তর্ভুক্ত)</template></span><b>{{ money(sale.vat) }}</b></div>
        <div class="rc-t"><span>মোট</span><span>{{ money(sale.total) }}</span></div>
        <div v-if="settings.show_payment_method" class="rm-meta-line"><span>পেমেন্ট মাধ্যম</span><b>{{ paymentMethodLabel }}</b></div>
        <div v-if="settings.show_tendered_change && tenderedTotal > 0" class="rm-meta-line"><span>দিয়েছেন</span><b>{{ money(tenderedTotal) }}</b></div>
        <div v-if="settings.show_tendered_change && changeReturned > 0" class="rm-meta-line"><span>ফেরত</span><b>{{ money(changeReturned) }}</b></div>
        <div v-if="sale.points_redeemed || sale.points_earned" class="rm-points">
            <span v-if="sale.points_redeemed">{{ t('pos.pointsRedeemedReceipt', { n: sale.points_redeemed }) }} </span>
            <span v-if="sale.points_earned">{{ t('pos.pointsEarnedReceipt', { n: sale.points_earned }) }}</span>
        </div>
        <div v-if="dueRemainder > 0" class="rm-due">বাকি বিল — {{ money(dueRemainder) }}</div>
        <div class="rc-f">{{ shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন' }}</div>
        <div v-if="settings.show_exchange_policy && settings.exchange_policy_text" class="rm-exchange">{{ settings.exchange_policy_text }}</div>
        <template v-if="showBarcodeAndQr">
            <div class="rm-barcode-wrap"><svg :id="barcodeId"></svg></div>
            <div v-if="sale.rating_url" class="rm-qr-wrap">
                <canvas :id="qrId"></canvas>
                <div class="rm-qr-hint">{{ t('sales.rateHint') }}</div>
            </div>
            <div v-if="settings.show_shop_qr && shopQrUrl" class="rm-qr-wrap">
                <canvas :id="shopQrId"></canvas>
                <div class="rm-qr-hint">{{ shop?.website }}</div>
            </div>
        </template>
        <div class="rc-brand">
            <template v-if="platformLogoUrl"><img :src="platformLogoUrl" alt="Zaylotix"></template>
            <template v-else>A Zaylotix product · zaylotix.com</template>
        </div>
    </div>
</template>

<style>
.receipt-memo { font-size: 11px; }
.receipt-memo h3 { font-size: 13px; margin: 0 0 4px; text-align: center; }
.rm-logo { max-width: 120px; display: block; margin: 0 auto 8px; }
.receipt-memo .rc-sub { font-size: 10px; text-align: center; margin-bottom: 6px; color: #555; }
.rm-customer { font-size: 11px; color: #666; margin-bottom: 6px; }
.receipt-memo .rc-l { display: flex; justify-content: space-between; padding: 2px 0; }
.receipt-memo .rc-l span:first-child { word-break: break-word; padding-right: 6px; }
.rm-item-discount { color: #c0392b; font-size: 10.5px; }
.rm-subtotal { display: flex; justify-content: space-between; padding: 2px 0; font-size: 10.5px; color: #555; border-top: 1px dashed #999; margin-top: 4px; padding-top: 4px; }
.receipt-memo .rc-t { display: flex; justify-content: space-between; font-weight: 800; font-size: 13px; border-top: 1px dashed #000; margin-top: 4px; padding-top: 4px; }
.rm-meta-line { display: flex; justify-content: space-between; padding: 1px 0; font-size: 10.5px; color: #555; }
.rm-points { text-align: center; color: #555; margin-top: 6px; font-size: 10.5px; }
.rm-due { text-align: center; color: #c0392b; font-weight: 700; margin-top: 8px; font-size: 12px; }
.rm-offline-pending { color: #c0392b; font-weight: 700; }
.receipt-memo .rc-f { text-align: center; color: #555; margin-top: 8px; font-size: 10.5px; white-space: pre-wrap; }
.rm-exchange { text-align: center; color: #555; margin-top: 4px; font-size: 9.5px; white-space: pre-wrap; }
.rm-barcode-wrap { display: flex; justify-content: center; margin-top: 8px; }
.rm-qr-wrap { display: flex; flex-direction: column; align-items: center; margin-top: 6px; gap: 2px; }
.rm-qr-hint { font-size: 9px; color: #666; }
.receipt-memo .rc-brand { text-align: center; font-size: 9px; color: #999; margin-top: 10px; }
.receipt-memo .rc-brand img { height: 14px; vertical-align: middle; opacity: .7; }

/* on-screen (settings preview) sizing — the @media print block in
   Sales/Show.vue takes over width/padding for the real printed page */
.receipt-memo { width: 280px; margin: 0 auto; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }

/* Phase 45 — optional solid frame around the whole memo (Baby Park's
   "professional framed receipt" ask). A real printed border, so it must
   survive both the @media print path (which strips the preview border
   above via `border: none !important`) and the Bluetooth capture — hence
   its own class with !important of its own, only when the toggle is on. */
.receipt-memo.rm-framed { border: 2px solid #000 !important; border-radius: 0; padding: 12px 10px !important; }
</style>
