<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ partners: Array, totalOwnershipPercent: Number, retainedProfit: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const sheet = ref(false);
const form = useForm({ name: '', phone: '', ownership_percent: '', invested_amount: '', method: 'cash' });
function save() {
    form.post(route('app.partners.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
}

const txSheet = ref(false);
const txPartner = ref(null);
const txForm = useForm({ type: 'investment', amount: '', method: 'cash', note: '' });
function openTx(partner) {
    txPartner.value = partner;
    txForm.reset();
    txSheet.value = true;
}
function submitTx() {
    router.post(route('app.partners.transactions.store', txPartner.value.id), txForm.data(), {
        onSuccess: () => { txSheet.value = false; },
    });
}
</script>

<template>
    <Head :title="t('partner.title')" />
    <AppLayout active="partners">
        <div class="pgttl">{{ t('partner.title') }}</div>
        <div class="pgsub">{{ t('partner.subtitle') }}</div>

        <div class="f2" style="margin-bottom:16px">
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:13px">{{ t('partner.retainedProfit') }}</div>
                <div style="font-size:20px;font-weight:850;color:var(--green)">{{ money(retainedProfit) }}</div>
            </div>
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:13px">{{ t('partner.totalOwnership') }}</div>
                <div style="font-size:20px;font-weight:850" :style="{ color: totalOwnershipPercent > 100 ? 'var(--rose)' : 'var(--tx)' }">{{ totalOwnershipPercent }}%</div>
            </div>
        </div>

        <button class="btn" style="margin-bottom:16px" @click="sheet = true">{{ t('partner.addPartner') }}</button>

        <div v-for="p in partners" :key="p.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:10px">
                <div>
                    <b style="font-size:15px">{{ p.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ p.ownership_percent }}% • {{ p.phone || '-' }}</div>
                </div>
                <button class="btn ghost" style="width:auto;padding:8px 14px;font-size:12.5px" @click="openTx(p)">{{ t('partner.addTransaction') }}</button>
            </div>
            <div class="grid2" style="gap:8px;font-size:12.5px">
                <div><span style="color:var(--mut)">{{ t('partner.invested') }}: </span><b>{{ money(p.invested_amount) }}</b></div>
                <div><span style="color:var(--mut)">{{ t('partner.withdrawn') }}: </span><b>{{ money(p.withdrawn_amount) }}</b></div>
                <div><span style="color:var(--mut)">{{ t('partner.profitShare') }}: </span><b style="color:var(--green)">{{ money(p.profit_share) }}</b></div>
                <div><span style="color:var(--mut)">{{ t('partner.netPosition') }}: </span><b>{{ money(p.net_position) }}</b></div>
            </div>
        </div>
        <div v-if="!partners.length" class="empty"><div class="big">🤝</div>{{ t('partner.noPartners') }}</div>

        <Sheet v-model="sheet" :title="t('partner.addPartner')">
            <div class="field"><label>{{ t('partner.name') }}</label><input v-model="form.name"></div>
            <div class="field"><label>{{ t('partner.phone') }}</label><input v-model="form.phone"></div>
            <div class="field">
                <label>{{ t('partner.ownershipPercent') }}</label>
                <input v-model="form.ownership_percent" type="number" step="0.01">
                <div v-if="form.errors.ownership_percent" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.ownership_percent }}</div>
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('partner.initialInvestment') }}</label><input v-model="form.invested_amount" type="number"></div>
                <div class="field">
                    <label>{{ t('partner.method') }}</label>
                    <select v-model="form.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
                </div>
            </div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('partner.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="txSheet" :title="t('partner.addTransaction')">
            <div class="field">
                <label>{{ t('cl.type') }}</label>
                <div class="seg">
                    <button :class="{ on: txForm.type === 'investment' }" @click="txForm.type = 'investment'">{{ t('partner.investMore') }}</button>
                    <button :class="{ on: txForm.type === 'withdrawal' }" @click="txForm.type = 'withdrawal'">{{ t('partner.withdraw') }}</button>
                </div>
            </div>
            <div class="field">
                <label>{{ t('partner.amount') }}</label>
                <input v-model="txForm.amount" type="number">
                <div v-if="txForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ txForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('partner.method') }}</label>
                <select v-model="txForm.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
            </div>
            <div class="field"><label>{{ t('partner.note') }}</label><input v-model="txForm.note"></div>
            <button class="btn" :disabled="txForm.processing" @click="submitTx">{{ txForm.processing ? '...' : t('partner.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="txSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
