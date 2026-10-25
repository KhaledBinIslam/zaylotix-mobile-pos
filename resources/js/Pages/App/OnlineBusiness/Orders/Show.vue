<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

const props = defineProps({ order: Object, history: Array, couriers: { type: Array, default: () => [] } });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const NEXT_SIMPLE = {
    pending: 'confirmed', confirmed: 'packed', packed: 'handed_to_courier', handed_to_courier: 'in_transit',
};
const canCancel = computed(() => ['pending', 'confirmed', 'packed', 'handed_to_courier', 'in_transit', 'failed'].includes(props.order.status));

const trackingForm = useForm({ status: '', tracking_id: '', courier_id: '', parcel_weight: '', is_outside_dhaka: false });
const selectedCourier = computed(() => props.couriers.find((c) => c.id === trackingForm.courier_id));
const courierSheet = ref(false);

function advance() {
    const next = NEXT_SIMPLE[props.order.status];
    if (!next) return;
    trackingForm.status = next;
    trackingForm.tracking_id = props.order.tracking_id || '';

    // "handed to courier" with no courier picked yet (at order creation or
    // the channel's own default) — the last natural point to still name
    // one, see docs/FIX_PLAN.md's Phase 33 design note
    if (next === 'handed_to_courier' && !props.order.courier_id && props.couriers.length) {
        courierSheet.value = true;
        return;
    }

    trackingForm.patch(route('app.onlineOrders.transition', props.order.id));
}
function confirmCourierAndAdvance() {
    trackingForm.patch(route('app.onlineOrders.transition', props.order.id), {
        onSuccess: () => (courierSheet.value = false),
    });
}
function markFailed() {
    if (!confirm(t('onlineOrder.confirmFailed'))) return;
    router.patch(route('app.onlineOrders.transition', props.order.id), { status: 'failed' });
}
function startReturning() {
    if (!confirm(t('onlineOrder.confirmReturning'))) return;
    router.patch(route('app.onlineOrders.transition', props.order.id), { status: 'returning' });
}
function deliver() {
    if (!confirm(t('onlineOrder.confirmDeliver'))) return;
    router.post(route('app.onlineOrders.deliver', props.order.id));
}
function cancelOrder() {
    const reason = prompt(t('onlineOrder.cancelReasonPrompt'));
    if (!reason) return;
    router.post(route('app.onlineOrders.cancel', props.order.id), { reason });
}
function returnOrder() {
    const reason = prompt(t('onlineOrder.returnReasonPrompt'));
    if (!reason) return;
    router.post(route('app.onlineOrders.return', props.order.id), { reason });
}

function waUpdate() {
    const phone = props.order.sale?.customer?.phone;
    if (!phone) { toast('❌ ' + t('onlineOrder.noPhone')); return; }
    const num = '88' + phone.replace(/\D/g, '').replace(/^88/, '');
    const msg = t('onlineOrder.waStatusMessage', { order: props.order.order_number, status: t('onlineOrder.status.' + props.order.status) });
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(msg), '_blank');
}
</script>

<template>
    <Head :title="order.order_number" />
    <AppLayout active="onlineOrders">
        <div class="pgttl">{{ order.order_number }}</div>
        <div class="pgsub">{{ order.channel.name }} • {{ order.sale?.invoice_no }}</div>

        <Link :href="route('app.onlineOrders.index')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← {{ t('common.back') }}</Link>

        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span class="pill gold">{{ t('onlineOrder.status.' + order.status) }}</span>
                <b style="font-size:20px;color:var(--gold)">{{ money(order.sale?.total || 0) }}</b>
            </div>
            <div style="color:var(--mut);font-size:13px">{{ order.sale?.customer?.name }} • 📞 {{ order.sale?.customer?.phone }}</div>
            <div v-if="order.courier" style="color:var(--mut);font-size:13px;margin-top:4px">🛵 {{ order.courier.name }}<template v-if="order.courier_charge"> • {{ money(order.courier_charge) }}</template></div>
            <div v-if="order.tracking_id" style="color:var(--mut);font-size:13px;margin-top:4px">{{ t('onlineOrder.trackingId') }}: {{ order.tracking_id }}</div>
            <div v-if="Number(order.cod_amount) > 0" style="color:var(--mut);font-size:13px;margin-top:4px">{{ t('onlineOrder.codAmount') }}: {{ money(order.cod_amount) }}<template v-if="order.cod_settled_at"> ✅ {{ t('courier.settleSelected') }}</template></div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="k" style="color:var(--mut);font-size:12px;margin-bottom:8px">{{ t('quotation.items') }}</div>
            <div v-for="item in order.sale?.items || []" :key="item.id" style="display:flex;justify-content:space-between;padding:4px 0">
                <span>{{ item.product_name }}<template v-if="item.variant_label"> ({{ item.variant_label }})</template> × {{ item.qty }}</span>
                <b>{{ money(item.price * item.qty) }}</b>
            </div>
        </div>

        <div class="btnrow" style="margin-bottom:14px;flex-wrap:wrap">
            <button v-if="NEXT_SIMPLE[order.status]" class="btn sm" style="flex:1" @click="advance">{{ t('onlineOrder.advanceTo.' + NEXT_SIMPLE[order.status]) }}</button>
            <button v-if="order.status === 'in_transit'" class="btn sm" style="flex:1" @click="deliver">{{ t('onlineOrder.markDelivered') }}</button>
            <button v-if="order.status === 'in_transit'" class="btn sm ghost" style="flex:1" @click="markFailed">{{ t('onlineOrder.markFailed') }}</button>
            <button v-if="order.status === 'delivered' || order.status === 'failed'" class="btn sm ghost" style="flex:1" @click="startReturning">{{ t('onlineOrder.startReturning') }}</button>
            <button v-if="order.status === 'returning'" class="btn sm rose" style="flex:1" @click="returnOrder">{{ t('onlineOrder.confirmReturn') }}</button>
            <button v-if="canCancel" class="btn sm rose" style="flex:1" @click="cancelOrder">{{ t('onlineOrder.cancelOrder') }}</button>
        </div>

        <div class="btnrow" style="margin-bottom:16px">
            <Link v-if="order.sale" :href="route('app.sales.show', order.sale.id)" class="btn ghost sm" style="flex:1;text-align:center;text-decoration:none">{{ t('onlineOrder.viewInvoice') }}</Link>
            <button class="btn wa sm" style="flex:1" @click="waUpdate">{{ t('onlineOrder.sendWaUpdate') }}</button>
        </div>

        <div class="sechead"><h2>{{ t('onlineOrder.history') }}</h2></div>
        <div v-for="h in history" :key="h.id" class="card" style="margin-bottom:8px">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--mut)">
                <span>{{ h.created_at.slice(0, 16).replace('T', ' ') }}<template v-if="h.user"> • {{ h.user.name }}</template></span>
            </div>
            <div style="margin-top:4px">{{ h.description }}</div>
        </div>

        <Sheet v-model="courierSheet" :title="t('courier.pickCourier')">
            <div class="field">
                <label>{{ t('courier.pickCourier') }}</label>
                <select v-model="trackingForm.courier_id">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div v-if="selectedCourier?.charge_type === 'weight'" class="field">
                <label>{{ t('courier.parcelWeight') }}</label>
                <input v-model.number="trackingForm.parcel_weight" type="number" min="0" step="0.01" placeholder="0.5">
            </div>
            <div v-if="selectedCourier?.charge_type === 'zone'" class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="trackingForm.is_outside_dhaka" type="checkbox">
                    {{ t('courier.outsideDhaka') }}
                </label>
            </div>
            <button class="btn" :disabled="trackingForm.processing" @click="confirmCourierAndAdvance">{{ trackingForm.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="courierSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
