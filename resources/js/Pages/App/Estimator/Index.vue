<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ products: Array });
const { t } = useI18n();

const lines = ref([{ product_id: '', qty: 1 }]);
const totals = ref(null);
const loading = ref(false);
const error = ref('');

function addLine() {
    lines.value.push({ product_id: '', qty: 1 });
}
function removeLine(idx) {
    lines.value.splice(idx, 1);
}

async function calculate() {
    const valid = lines.value.filter((l) => l.product_id && l.qty > 0);
    if (!valid.length) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(route('app.estimator.calculate'), { lines: valid });
        totals.value = data.totals;
    } catch (e) {
        error.value = t('estimator.errorHint');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head :title="t('estimator.title')" />
    <AppLayout active="estimator">
        <div class="pgttl">{{ t('estimator.title') }}</div>
        <div class="pgsub">{{ t('estimator.subtitle') }}</div>

        <div class="card" style="margin-bottom:16px">
            <div v-for="(line, idx) in lines" :key="idx" style="display:flex;gap:6px;margin-bottom:10px;align-items:center">
                <select v-model="line.product_id" style="flex:1;margin:0">
                    <option value="">{{ t('estimator.selectFood') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                </select>
                <input v-model.number="line.qty" type="number" min="1" style="width:80px;margin:0">
                <button class="btn sm ghost" style="width:auto;padding:8px 10px" @click="removeLine(idx)">✕</button>
            </div>
            <button class="btn sm ghost" style="margin-bottom:12px" @click="addLine">+ {{ t('estimator.addFood') }}</button>
            <button class="btn" :disabled="loading" @click="calculate">{{ loading ? '...' : t('estimator.calculateButton') }}</button>
            <div v-if="!products.length" style="font-size:12px;color:var(--dim);margin-top:10px">{{ t('prep.noRecipeHint') }}</div>
            <div v-if="error" style="color:var(--rose);font-size:12.5px;margin-top:10px">{{ error }}</div>
        </div>

        <template v-if="totals">
            <div class="sechead"><h2>{{ t('estimator.resultHeading') }}</h2></div>
            <div v-for="row in totals" :key="row.name" class="row" style="cursor:default">
                <div class="ava">🧂</div>
                <div class="mid"><b>{{ row.name }}</b></div>
                <div class="end"><b>{{ row.qty }} {{ row.unit }}</b></div>
            </div>
            <div v-if="!totals.length" class="empty"><div class="big">🧂</div>{{ t('estimator.noResult') }}</div>
        </template>
    </AppLayout>
</template>
