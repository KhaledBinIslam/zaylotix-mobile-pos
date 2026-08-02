<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ preparations: Array, products: Array });
const { t } = useI18n();

const sheet = ref(false);
const form = useForm({ product_id: '', qty: '' });
function openNew() {
    form.reset();
    sheet.value = true;
}
function submit() {
    form.post(route('app.preparations.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
}
</script>

<template>
    <Head :title="t('prep.title')" />
    <AppLayout active="preparations">
        <div class="pgttl">{{ t('prep.title') }}</div>
        <div class="pgsub">{{ t('prep.subtitle') }}</div>

        <button class="btn" style="margin-bottom:16px" @click="openNew">{{ t('prep.addPreparation') }}</button>

        <div v-for="p in preparations" :key="p.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <b style="font-size:15px">{{ p.product_name }}</b>
                <span style="font-size:12px;color:var(--dim)">{{ p.created_at }}</span>
            </div>
            <div style="font-size:13px;color:var(--mut);margin-top:2px">{{ t('prep.producedQty') }}: <b>{{ p.qty }}</b></div>
            <div v-if="p.items?.length" style="margin-top:8px;padding-top:8px;border-top:1px solid var(--line)">
                <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:4px">{{ t('prep.consumedHeading') }}</div>
                <div v-for="it in p.items" :key="it.id" style="display:flex;justify-content:space-between;font-size:12.5px;padding:2px 0">
                    <span>{{ it.ingredient_name }}</span><span>{{ it.qty_consumed }}</span>
                </div>
            </div>
        </div>
        <div v-if="!preparations.length" class="empty"><div class="big">🍳</div>{{ t('prep.noPreparations') }}</div>

        <Sheet v-model="sheet" :title="t('prep.addPreparation')">
            <div class="field">
                <label>{{ t('prep.product') }}</label>
                <select v-model="form.product_id">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                </select>
                <div v-if="!products.length" style="font-size:12px;color:var(--dim);margin-top:6px">{{ t('prep.noRecipeHint') }}</div>
            </div>
            <div class="field">
                <label>{{ t('prep.qtyToProduceLabel') }}</label>
                <input v-model="form.qty" type="number" min="0" step="0.001">
                <div v-if="form.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.qty }}</div>
            </div>
            <button class="btn" :disabled="form.processing || !form.product_id" @click="submit">{{ form.processing ? '...' : t('prep.recordButton') }}</button>
        </Sheet>
    </AppLayout>
</template>
