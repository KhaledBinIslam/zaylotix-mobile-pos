<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { findSimilarNames } from '@/support/similarNames';

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
    // Khaled live: "Sujon" and "Sujon 3rd party" — two real, separate
    // suppliers with zero warning either was close to the other. A soft,
    // skippable check (the server's own guard still hard-blocks an exact
    // twin) — never silently blocks, just names what it found and asks.
    const similar = findSimilarNames(addForm.name, props.suppliers.map((s) => s.name));
    if (similar.length && !confirm(t('supplier.similarNameConfirm', { name: addForm.name, similar: similar.join(', ') }))) {
        return;
    }
    addForm.post(route('app.suppliers.store'), { onSuccess: () => { addSheet.value = false; addForm.reset(); } });
}

// delete — for a just-created duplicate/typo; the server refuses the
// moment any purchase/payment/intake history exists, so this can never
// erase hisab. Button recovery: disabled while the request runs.
const page = usePage();
const deleting = ref(false);
function destroySupplier(s) {
    if (!confirm(t('supplier.deleteConfirm', { name: s.name }))) return;
    deleting.value = true;
    router.delete(route('app.suppliers.destroy', s.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = false),
    });
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

// Khaled live: "Sujon" and "Sujon 3rd party" turned out to be the same
// real supplier. Same shape as Brand's own merge (manual survivor pick,
// never auto-matched) — everything repoints to the survivor, including
// the payable (summed, never silently dropped), see SupplierController::merge().
const mergeSheet = ref(false);
const merging = ref(null);
const mergeInto = ref('');
function openMerge(s) {
    merging.value = s;
    mergeInto.value = '';
    mergeSheet.value = true;
}
function confirmMerge() {
    router.post(route('app.suppliers.merge', merging.value.id), { into: mergeInto.value }, {
        onSuccess: () => { mergeSheet.value = false; },
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

        <!-- why a delete was refused — stays visible, never a fading toast -->
        <div v-if="page.props.errors?.supplier" class="card" style="margin-bottom:12px;background:var(--roseSoft);color:var(--rose);font-size:13px">
            ⚠ {{ page.props.errors.supplier }}
        </div>

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
                    <button v-if="suppliers.length > 1" class="btn sm ghost" style="flex:0 0 auto;width:auto;padding:6px 10px" @click="openMerge(s)">{{ t('supplier.merge') }}</button>
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
                <div class="end" style="display:flex;align-items:center;gap:8px">
                    <span class="pill mint">{{ t('supplier.settled') }}</span>
                    <button v-if="suppliers.length > 1" class="btn sm ghost" style="width:auto;padding:6px 10px" @click.prevent.stop="openMerge(s)">{{ t('supplier.merge') }}</button>
                    <!-- duplicate/typo rows only — the server refuses any
                         supplier with real history, so this stays safe -->
                    <button class="btn sm ghost rose" style="width:auto;padding:6px 10px" :disabled="deleting" @click.prevent.stop="destroySupplier(s)">🗑</button>
                </div>
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

        <Sheet v-model="mergeSheet" :title="t('supplier.mergeTitle', { name: merging?.name || '' })">
            <div style="font-size:13px;color:var(--mut);margin-bottom:12px">{{ t('supplier.mergeHint') }}</div>
            <div class="field">
                <label>{{ t('supplier.mergeInto') }}</label>
                <select v-model="mergeInto">
                    <option value="" disabled>{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="s in suppliers.filter((x) => x.id !== merging?.id)" :key="s.id" :value="s.id">{{ s.name }} ({{ s.stats?.products || 0 }})</option>
                </select>
            </div>
            <button class="btn" :disabled="!mergeInto" @click="confirmMerge">{{ t('supplier.mergeDo') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="mergeSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
