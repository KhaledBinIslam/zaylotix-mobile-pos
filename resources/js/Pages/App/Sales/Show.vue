<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ sale: Object });

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
            <div class="rc-sub">📞 {{ shop?.phone }} • {{ shop?.area }}<br>মেমো — {{ sale.invoice_no }}</div>
            <div style="font-size:11px;color:#666;margin-bottom:6px">কাস্টমার: {{ sale.customer?.name || 'নগদ কাস্টমার' }}</div>
            <div v-for="l in sale.items" :key="l.id" class="rc-l">
                <span>{{ l.product_name }}{{ (l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : '' }} ×{{ l.qty }}</span><b>{{ money(l.price * l.qty) }}</b>
            </div>
            <div v-if="sale.discount > 0" class="rc-l"><span>ছাড়{{ sale.coupon_code ? ` (${sale.coupon_code})` : '' }}</span><b>− {{ money(sale.discount) }}</b></div>
            <div class="rc-t"><span>মোট</span><span>{{ money(sale.total) }}</span></div>
            <div v-if="sale.points_redeemed || sale.points_earned" style="text-align:center;color:#555;margin-top:6px;font-size:10.5px">
                <span v-if="sale.points_redeemed">{{ t('pos.pointsRedeemedReceipt', { n: sale.points_redeemed }) }} </span>
                <span v-if="sale.points_earned">{{ t('pos.pointsEarnedReceipt', { n: sale.points_earned }) }}</span>
            </div>
            <div style="text-align:center;color:#c0392b;font-weight:700;margin-top:8px;font-size:12px" v-if="dueRemainder > 0">বাকি বিল — {{ money(dueRemainder) }}</div>
            <div class="rc-f">{{ shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏' }}</div>
            <div class="rc-brand">
                <template v-if="platformLogoUrl"><img :src="platformLogoUrl" alt="Zaylotix"></template>
                <template v-else>A Zaylotix product · zaylotix.com</template>
            </div>
        </div>
        </div>

        <div class="no-print btnrow" style="margin-top:16px">
            <button v-if="features.includes('memo_whatsapp')" class="btn wa" style="flex:1" @click="sendMemoWA">📤 WhatsApp</button>
            <button v-if="features.includes('memo_print')" class="btn ghost" style="flex:1" @click="printMemo">{{ t('pos.print') }}{{ tableOrder ? ' (' + t('restaurant.kotTitle') + ' + ' + t('sales.memoLabel') + ')' : '' }}</button>
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
