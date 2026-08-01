<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ quotation: Object });
const { t } = useI18n();
const page = usePage();
const shop = computed(() => page.props.shop);
const features = computed(() => page.props.features || []);

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const statusLabel = { open: 'quotation.statusOpen', converted: 'quotation.statusConverted', cancelled: 'quotation.statusCancelled' };

const waPhone = computed(() => props.quotation.customer?.phone || props.quotation.customer_phone || '');

function print() {
    window.print();
}

function sendQuotationWA() {
    const q = props.quotation;
    const num = '88' + waPhone.value.replace(/\D/g, '').replace(/^88/, '');
    const lines = q.items.map((l) => `${l.product_name}  ${l.qty}×${l.price} = ${money(l.price * l.qty - (l.discount || 0))}`).join('\n');
    const validity = q.valid_until ? `\n${t('quotation.validUntilLabel')} ${q.valid_until}` : '';
    const text = `*${shop.value?.name || 'Zaylotix POS'}*\n${'─'.repeat(16)}\n${t('quotation.quoteNoLabel')}: ${q.quote_no}\n${'─'.repeat(16)}\n${lines}\n${'─'.repeat(16)}\n*${t('pos.grandTotal')}: ${money(q.total)}*${validity}\n\nধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
}
function convertToSale() {
    router.visit(route('app.pos') + '?quotation=' + props.quotation.id);
}
function cancelQuotation() {
    if (!confirm(t('quotation.cancelConfirm'))) return;
    router.post(route('app.quotations.cancel', props.quotation.id));
}
</script>

<template>
    <Head :title="quotation.quote_no" />
    <AppLayout active="quotations">
        <div class="no-print pgttl">{{ quotation.quote_no }}</div>
        <div class="no-print pgsub">{{ quotation.date }}<span v-if="quotation.valid_until"> • {{ t('quotation.validUntilLabel') }} {{ quotation.valid_until }}</span></div>

        <div id="printable-memo" class="receipt">
            <img v-if="shop?.logo_url" :src="shop.logo_url" style="max-width:120px;display:block;margin:0 auto 8px">
            <h3>{{ shop?.name || 'Zaylotix POS' }}</h3>
            <div class="rc-sub">📞 {{ shop?.phone }} • {{ shop?.area }}<br>{{ t('quotation.quoteNoLabel') }} — {{ quotation.quote_no }}</div>
            <div style="font-size:11px;color:#666;margin-bottom:6px">{{ t('quotation.customerLabel') }}: {{ quotation.customer?.name || quotation.customer_name || t('quotation.walkIn') }}</div>
            <div v-for="l in quotation.items" :key="l.id" class="rc-l">
                <span>{{ l.product_name }} ×{{ l.qty }}
                    <template v-if="l.discount > 0"><br><span style="color:#c0392b;font-size:10.5px">ছাড় −{{ money(l.discount) }}</span></template>
                </span>
                <b>{{ money(l.price * l.qty - (l.discount || 0)) }}</b>
            </div>
            <div v-if="quotation.discount > 0" class="rc-l"><span>{{ t('pos.overallDiscount') }}</span><b>− {{ money(quotation.discount) }}</b></div>
            <div class="rc-t"><span>{{ t('pos.grandTotal') }}</span><span>{{ money(quotation.total) }}</span></div>
            <div v-if="quotation.valid_until" style="text-align:center;color:#666;margin-top:8px;font-size:11px">{{ t('quotation.validUntilLabel') }} {{ quotation.valid_until }}</div>
            <div class="rc-f">ধন্যবাদ! 🙏</div>
            <div class="rc-brand">A Zaylotix product · zaylotix.com</div>
        </div>

        <div v-if="quotation.notes" class="no-print card" style="margin-top:16px">
            <div style="font-size:12px;color:var(--mut);font-weight:700;margin-bottom:4px">{{ t('quotation.notes') }}</div>
            <div style="font-size:13px;white-space:pre-wrap">{{ quotation.notes }}</div>
        </div>

        <div class="no-print btnrow" style="margin-top:16px">
            <button v-if="features.includes('memo_whatsapp') && waPhone" class="btn wa" style="flex:1" @click="sendQuotationWA">📤 WhatsApp</button>
            <button class="btn ghost" style="flex:1" @click="print">{{ t('pos.print') }}</button>
        </div>
        <div v-if="features.includes('memo_whatsapp') && !waPhone" class="no-print" style="font-size:12px;color:var(--mut);margin-top:8px">{{ t('quotation.waNeedsPhone') }}</div>

        <template v-if="quotation.status === 'open'">
            <button class="no-print btn" style="margin-top:16px" @click="convertToSale">{{ t('quotation.convertToSale') }}</button>
            <button class="no-print btn rose" style="margin-top:10px" @click="cancelQuotation">{{ t('quotation.cancel') }}</button>
        </template>
        <div v-else class="no-print card" style="margin-top:16px;text-align:center">
            <b>{{ t(statusLabel[quotation.status]) }}</b>
        </div>
    </AppLayout>
</template>
