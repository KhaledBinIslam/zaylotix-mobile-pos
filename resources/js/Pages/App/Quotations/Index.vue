<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ quotations: Array, products: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const statusLabel = { open: 'quotation.statusOpen', converted: 'quotation.statusConverted', cancelled: 'quotation.statusCancelled' };
const statusPill = { open: 'gold', converted: 'mint', cancelled: 'mut' };
// Confirmed gap (see docs/FIX_PLAN.md Phase 6): an expired-but-open quote
// still showed the same "খোলা" (open) pill forever — nothing ever flagged
// it as stale in the list. Display-only; the actual conversion block lives
// server-side in PosController::performCheckout().
function isExpired(q) {
    if (q.status !== 'open' || !q.valid_until) return false;
    return new Date(q.valid_until) < new Date(new Date().toDateString());
}

const sheet = ref(false);
// Missing feature (see docs/FIX_PLAN.md Phase 6): "cancel and create a new
// one" was the only way to fix a mistake on an open quote. null = creating
// a new quotation; set to the quotation being edited otherwise — same
// sheet/form either way, save() just posts to a different route.
const editing = ref(null);
const form = useForm({
    customer_name: '', customer_phone: '', valid_until: '', notes: '', discount: '',
    items: [],
});

function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.items = [{ product_id: '', qty: 1, price: '', discount: '' }];
    sheet.value = true;
}
function openEdit(q) {
    editing.value = q;
    form.clearErrors();
    form.customer_name = q.customer_name || '';
    form.customer_phone = q.customer_phone || '';
    form.valid_until = q.valid_until || '';
    form.notes = q.notes || '';
    form.discount = Number(q.discount) || '';
    form.items = q.items.map((l) => ({ product_id: l.product_id, qty: Number(l.qty), price: Number(l.price), discount: Number(l.discount) || '' }));
    sheet.value = true;
}
function addLine() {
    form.items.push({ product_id: '', qty: 1, price: '', discount: '' });
}
function removeLine(i) {
    form.items.splice(i, 1);
}
function onProductPick(line) {
    const p = props.products.find((x) => x.id === line.product_id);
    if (p) line.price = p.price;
}
function isWeighed(line) {
    return !!props.products.find((x) => x.id === line.product_id)?.sold_by_weight;
}
const lineTotal = (l) => Math.max(0, (Number(l.price) || 0) * (Number(l.qty) || 0) - (Number(l.discount) || 0));
const subtotal = computed(() => form.items.reduce((s, l) => s + lineTotal(l), 0));
const total = computed(() => Math.max(0, subtotal.value - (Number(form.discount) || 0)));

function save() {
    const onSuccess = () => { sheet.value = false; editing.value = null; };
    if (editing.value) {
        form.put(route('app.quotations.update', editing.value.id), { onSuccess });
    } else {
        form.post(route('app.quotations.store'), { onSuccess });
    }
}
</script>

<template>
    <Head :title="t('nav.quotations')" />
    <AppLayout active="quotations">
        <div class="pgttl">{{ t('nav.quotations') }}</div>
        <div class="pgsub">{{ t('quotation.subtitle') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('quotation.add') }}</button>

        <div v-for="q in quotations" :key="q.id" class="row" style="cursor:default">
            <Link :href="route('app.quotations.show', q.id)" class="ava" style="text-decoration:none">📋</Link>
            <Link :href="route('app.quotations.show', q.id)" class="mid" style="text-decoration:none;color:inherit">
                <b>{{ q.quote_no }}</b>
                <span>{{ q.customer_name || t('quotation.walkIn') }} • {{ q.date }} • {{ q.items?.length || 0 }} {{ t('quotation.items') }}</span>
            </Link>
            <div class="end">
                <b>{{ money(q.total) }}</b>
                <span v-if="isExpired(q)" class="pill rose" style="margin-top:3px">{{ t('quotation.expired') }}</span>
                <span v-else class="pill" :class="statusPill[q.status]" style="margin-top:3px">{{ t(statusLabel[q.status]) }}</span>
                <button v-if="q.status === 'open'" type="button" class="btn sm ghost" style="width:auto;margin-top:4px;padding:4px 10px" @click="openEdit(q)">{{ t('common.edit') }}</button>
            </div>
        </div>
        <div v-if="!quotations.length" class="empty"><div class="big">📋</div>{{ t('quotation.noQuotations') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('quotation.editTitle') : t('quotation.newTitle')">
            <div class="field"><label>{{ t('quotation.customerName') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.customer_name"></div>
            <div class="field"><label>{{ t('quotation.customerPhone') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.customer_phone" inputmode="tel"></div>

            <div class="field"><label>{{ t('quotation.items') }}</label></div>
            <div v-for="(line, i) in form.items" :key="i" class="card" style="margin-bottom:10px">
                <select v-model="line.product_id" @change="onProductPick(line)" style="margin-bottom:8px">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} — {{ p.price }}৳</option>
                </select>
                <div class="f2">
                    <div class="field" style="margin:0"><label>{{ t('return.qty') }}</label><input v-model.number="line.qty" type="number" :min="isWeighed(line) ? 0.001 : 1" :step="isWeighed(line) ? 0.001 : 1"></div>
                    <div class="field" style="margin:0"><label>{{ t('stock.price') }}</label><input v-model.number="line.price" type="number"></div>
                </div>
                <div class="f2" style="margin-top:8px">
                    <div class="field" style="margin:0"><label>{{ t('quotation.lineDiscount') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model.number="line.discount" type="number"></div>
                    <button type="button" class="btn sm rose" style="align-self:flex-end" @click="removeLine(i)" :disabled="form.items.length <= 1">{{ t('common.cancel') }}</button>
                </div>
                <div v-if="form.errors[`items.${i}.product_id`]" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors[`items.${i}.product_id`] }}</div>
            </div>
            <button type="button" class="btn ghost sm" style="width:100%;margin-bottom:14px" @click="addLine">{{ t('quotation.addLine') }}</button>

            <div class="field"><label>{{ t('pos.overallDiscount') }}</label><input v-model.number="form.discount" type="number"></div>
            <div class="field"><label>{{ t('quotation.validUntil') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.valid_until" type="date"></div>
            <div class="field"><label>{{ t('quotation.notes') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><textarea v-model="form.notes" rows="2"></textarea></div>

            <div class="card" style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('pos.subtotal') }}</span><b>{{ money(subtotal) }}</b></div>
                <div class="hr" style="margin:8px 0"></div>
                <div style="display:flex;justify-content:space-between;font-size:19px;font-weight:800"><span>{{ t('pos.grandTotal') }}</span><b style="color:var(--gold)">{{ money(total) }}</b></div>
            </div>

            <button class="btn" :disabled="form.processing" @click="save">
                {{ form.processing ? '...' : t('quotation.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
