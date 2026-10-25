<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ couriers: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const chargeTypeLabel = (type) => t('courier.chargeType.' + type);

const sheet = ref(false);
const editing = ref(null);
const form = useForm({
    name: '', charge_type: 'flat', active: true,
    charge_config: { amount: '', base: '', per_kg: '', inside_dhaka: '', outside_dhaka: '' },
    cod_fee_percent: '', return_charge: '',
});

function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function openEdit(c) {
    editing.value = c;
    form.clearErrors();
    form.name = c.name;
    form.charge_type = c.charge_type;
    form.active = c.active;
    form.charge_config = {
        amount: c.charge_config?.amount ?? '', base: c.charge_config?.base ?? '', per_kg: c.charge_config?.per_kg ?? '',
        inside_dhaka: c.charge_config?.inside_dhaka ?? '', outside_dhaka: c.charge_config?.outside_dhaka ?? '',
    };
    form.cod_fee_percent = c.cod_fee_percent ?? '';
    form.return_charge = c.return_charge ?? '';
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.couriers.update', editing.value.id), { onSuccess: () => (sheet.value = false) });
    } else {
        form.post(route('app.couriers.store'), { onSuccess: () => (sheet.value = false) });
    }
}
function destroy(c) {
    if (!confirm(`"${c.name}" ${t('channel.deleteConfirm')}`)) return;
    useForm({}).delete(route('app.couriers.destroy', c.id));
}
</script>

<template>
    <Head :title="t('courier.title')" />
    <AppLayout active="couriers">
        <div class="pgttl">{{ t('courier.title') }}</div>
        <div class="pgsub">{{ t('courier.subtitle') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('courier.add') }}</button>

        <Link v-for="c in couriers" :key="c.id" :href="route('app.couriers.show', c.id)" class="row" style="text-decoration:none;color:inherit">
            <div class="ava">🛵</div>
            <div class="mid"><b>{{ c.name }}</b><span>{{ chargeTypeLabel(c.charge_type) }}</span></div>
            <div class="end">
                <span class="pill" :class="c.active ? 'mint' : 'rose'">{{ c.active ? t('channel.active') : t('channel.inactive') }}</span>
                <button type="button" class="btn sm ghost" style="margin-top:6px" @click.prevent="openEdit(c)">{{ t('channel.edit') }}</button>
            </div>
        </Link>
        <div v-if="!couriers.length" class="empty"><div class="big">🛵</div>{{ t('courier.noCouriers') }}</div>

        <Sheet v-model="sheet" :title="editing ? editing.name : t('courier.addSheetTitle')">
            <div class="field">
                <label>{{ t('courier.name') }}</label>
                <input v-model="form.name" :placeholder="t('courier.namePlaceholder')">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>

            <div class="field">
                <label>{{ t('courier.chargeTypeLabel') }}</label>
                <div class="seg" style="margin-top:4px">
                    <button type="button" :class="{ on: form.charge_type === 'flat' }" @click="form.charge_type = 'flat'">{{ t('courier.chargeType.flat') }}</button>
                    <button type="button" :class="{ on: form.charge_type === 'weight' }" @click="form.charge_type = 'weight'">{{ t('courier.chargeType.weight') }}</button>
                    <button type="button" :class="{ on: form.charge_type === 'zone' }" @click="form.charge_type = 'zone'">{{ t('courier.chargeType.zone') }}</button>
                </div>
            </div>

            <div v-if="form.charge_type === 'flat'" class="field">
                <label>{{ t('courier.flatAmount') }}</label>
                <input v-model.number="form.charge_config.amount" type="number" min="0">
                <div v-if="form.errors['charge_config.amount']" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors['charge_config.amount'] }}</div>
            </div>

            <div v-if="form.charge_type === 'weight'" class="f2">
                <div class="field" style="margin:0">
                    <label>{{ t('courier.weightBase') }}</label>
                    <input v-model.number="form.charge_config.base" type="number" min="0">
                </div>
                <div class="field" style="margin:0">
                    <label>{{ t('courier.weightPerKg') }}</label>
                    <input v-model.number="form.charge_config.per_kg" type="number" min="0">
                </div>
            </div>

            <div v-if="form.charge_type === 'zone'" class="f2">
                <div class="field" style="margin:0">
                    <label>{{ t('courier.insideDhaka') }}</label>
                    <input v-model.number="form.charge_config.inside_dhaka" type="number" min="0">
                </div>
                <div class="field" style="margin:0">
                    <label>{{ t('courier.outsideDhaka') }}</label>
                    <input v-model.number="form.charge_config.outside_dhaka" type="number" min="0">
                </div>
            </div>

            <div class="f2">
                <div class="field">
                    <label>{{ t('courier.codFeePercent') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                    <input v-model.number="form.cod_fee_percent" type="number" min="0" max="100">
                </div>
                <div class="field">
                    <label>{{ t('courier.returnCharge') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                    <input v-model.number="form.return_charge" type="number" min="0">
                </div>
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="form.active" type="checkbox">
                    {{ t('channel.active') }}
                </label>
            </div>

            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
