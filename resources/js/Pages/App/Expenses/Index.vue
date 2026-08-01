<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ expenses: Array, categories: Array, total: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const sheet = ref(false);
const form = useForm({ expense_category_id: '', new_category_name: '', memo: '', amount: '', method: 'cash' });
function save() {
    form.post(route('app.expenses.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
}
</script>

<template>
    <Head :title="t('exp.title')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('exp.title') }}</div>
        <div class="card" style="background:linear-gradient(135deg,var(--roseSoft),#fff);margin-bottom:16px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('exp.total') }}</div>
            <div style="font-size:30px;font-weight:850;color:var(--rose)">{{ money(total) }}</div>
        </div>
        <button class="btn" style="margin-bottom:16px" @click="sheet = true">{{ t('exp.add') }}</button>

        <div v-for="x in expenses" :key="x.id" class="row">
            <div class="ava">{{ x.category?.emoji || '💰' }}</div>
            <div class="mid"><b>{{ x.category?.name || t('exp.defaultCategory') }}</b><span>{{ x.memo }} • {{ x.date }} • {{ x.method === 'cash' ? t('exp.cash') : t('exp.bank') }}</span></div>
            <div class="end"><b style="color:var(--rose)">− {{ money(x.amount) }}</b></div>
        </div>
        <div v-if="!expenses.length" class="empty"><div class="big">💸</div>{{ t('exp.noExpenses') }}</div>

        <Sheet v-model="sheet" :title="t('exp.sheetTitle')">
            <div class="field">
                <label>{{ t('exp.type') }}</label>
                <select v-model="form.expense_category_id">
                    <option :value="''">{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.emoji }} {{ c.name }}</option>
                </select>
            </div>
            <div class="field"><label>{{ t('exp.orNewType') }}</label><input v-model="form.new_category_name"></div>
            <div class="field"><label>{{ t('exp.description') }}</label><input v-model="form.memo"></div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('exp.amount') }}</label>
                    <input v-model="form.amount" type="number">
                    <div v-if="form.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.amount }}</div>
                </div>
                <div class="field">
                    <label>{{ t('exp.source') }}</label>
                    <select v-model="form.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
                </div>
            </div>
            <button class="btn" :disabled="form.processing" @click="save">
                {{ form.processing ? '...' : t('exp.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
