<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ suppliers: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// Phase 47 — name/phone search + optional sold-revenue ordering over the
// backend's derived per-supplier stats (intake/sold, zero manual entry)
const q = ref('');
const sortBySold = ref(false);
const matches = (s) => !q.value
    || s.name.toLowerCase().includes(q.value.toLowerCase())
    || (s.phone || '').includes(q.value);

const withPayable = computed(() => props.suppliers.filter((s) => s.payable > 0 && matches(s)).sort((a, b) => b.payable - a.payable));
const cleared = computed(() => {
    const list = props.suppliers.filter((s) => s.payable <= 0 && matches(s));
    return sortBySold.value ? [...list].sort((a, b) => (b.stats?.sold_revenue || 0) - (a.stats?.sold_revenue || 0)) : list;
});
const totalPayable = computed(() => props.suppliers.reduce((s, x) => s + Number(x.payable), 0));

function statsLine(s) {
    if (!s.stats || !s.stats.products) return null;
    return t('supplier.statsLine', {
        products: s.stats.products,
        intake: money(s.stats.intake_value),
        revenue: money(s.stats.sold_revenue),
        units: Math.round(s.stats.sold_units),
    });
}

const addSheet = ref(false);
const addForm = useForm({ name: '', phone: '', address: '' });
function saveSupplier() {
    addForm.post(route('app.suppliers.store'), { onSuccess: () => { addSheet.value = false; addForm.reset(); } });
}

const paySheet = ref(false);
const paying = ref(null);
const payForm = useForm({ amount: '', method: 'cash' });
function openPay(s) {
    paying.value = s;
    payForm.reset();
    payForm.clearErrors();
    paySheet.value = true;
}
function pay() {
    payForm.post(route('app.suppliers.payments.store', paying.value.id), {
        onSuccess: () => (paySheet.value = false),
    });
}
</script>

<template>
    <Head :title="t('supplier.title')" />
    <AppLayout active="suppliers">
        <div class="pgttl">{{ t('supplier.title') }}</div>
        <div class="pgsub">{{ t('supplier.subtitle') }}</div>

        <div class="card" style="background:var(--roseSoft);margin-bottom:16px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('supplier.totalPayable') }}</div>
            <div style="font-size:30px;font-weight:850;color:var(--rose)">{{ money(totalPayable) }}</div>
        </div>

        <button class="btn ghost" style="margin-bottom:16px" @click="addSheet = true">{{ t('supplier.add') }}</button>

        <input v-model="q" :placeholder="t('supplier.searchPlaceholder')" style="margin-bottom:10px">
        <label class="chk" style="margin-bottom:12px"><input type="checkbox" v-model="sortBySold"> {{ t('supplier.sortBySold') }}</label>

        <template v-if="withPayable.length">
            <div class="sechead"><h2>{{ t('supplier.owePending') }}</h2></div>
            <div v-for="s in withPayable" :key="s.id">
                <div class="row">
                    <div class="ava">{{ s.name[0] }}</div>
                    <Link :href="route('app.suppliers.show', s.id)" class="mid" style="text-decoration:none;color:inherit">
                        <b>{{ s.name }}</b>
                        <span>📞 {{ s.phone || t('due.noNumber') }}</span>
                        <span v-if="statsLine(s)" style="color:var(--gold2);font-size:11.5px">{{ statsLine(s) }}</span>
                    </Link>
                    <div class="end"><b class="pill rose">{{ money(s.payable) }}</b></div>
                </div>
                <div class="btnrow" style="margin:-4px 0 12px 0;padding-left:54px">
                    <button class="btn sm" style="flex:1" @click="openPay(s)">{{ t('supplier.pay') }}</button>
                    <Link :href="route('app.suppliers.show', s.id)" class="btn sm ghost" style="flex:1;text-align:center">{{ t('supplier.viewLedger') }}</Link>
                </div>
            </div>
        </template>

        <template v-if="cleared.length">
            <div class="sechead"><h2>{{ t('supplier.allSuppliers') }}</h2></div>
            <Link v-for="s in cleared" :key="s.id" :href="route('app.suppliers.show', s.id)" class="row" style="text-decoration:none;color:inherit">
                <div class="ava">{{ s.name[0] }}</div>
                <div class="mid">
                    <b>{{ s.name }}</b>
                    <span>📞 {{ s.phone || t('due.noNumber') }}</span>
                    <span v-if="statsLine(s)" style="color:var(--gold2);font-size:11.5px">{{ statsLine(s) }}</span>
                </div>
                <div class="end"><span class="pill mint">{{ t('supplier.settled') }}</span></div>
            </Link>
        </template>

        <div v-if="!suppliers.length" class="empty"><div class="big">🚚</div>{{ t('supplier.noSuppliers') }}</div>

        <Sheet v-model="addSheet" :title="t('supplier.addSheetTitle')">
            <div class="field">
                <label>{{ t('supplier.name') }}</label>
                <input v-model="addForm.name" :placeholder="t('supplier.namePlaceholder')">
                <div v-if="addForm.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('due.mobile') }}</label>
                <input v-model="addForm.phone" placeholder="01812-111222">
                <div v-if="addForm.errors.phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.phone }}</div>
            </div>
            <div class="field"><label>{{ t('supplier.address') }}</label><input v-model="addForm.address"></div>
            <button class="btn" :disabled="addForm.processing" @click="saveSupplier">
                {{ addForm.processing ? '...' : t('stock.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="addSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="paySheet" :title="paying?.name">
            <div v-if="paying" class="card" style="margin-bottom:16px;text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('supplier.currentPayable') }}</div>
                <div style="font-size:28px;font-weight:850;color:var(--rose)">{{ money(paying.payable) }}</div>
            </div>
            <div class="field">
                <label>{{ t('supplier.payAmount') }}</label>
                <input v-model="payForm.amount" type="number" :placeholder="String(paying?.payable || 0)">
                <div v-if="payForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ payForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('supplier.paySource') }}</label>
                <select v-model="payForm.method">
                    <option value="cash">{{ t('pay.cash') }}</option>
                    <option value="bkash">{{ t('pay.bkash') }}</option>
                    <option value="nagad">{{ t('pay.nagad') }}</option>
                </select>
            </div>
            <button class="btn" :disabled="payForm.processing" @click="pay">
                {{ payForm.processing ? '...' : t('supplier.paySubmit') }}
            </button>
        </Sheet>
    </AppLayout>
</template>
