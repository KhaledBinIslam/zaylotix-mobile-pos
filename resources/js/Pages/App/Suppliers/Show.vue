<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ supplier: Object, purchases: Array, returns: Array, payments: Array, totals: Object, products: Array });
const { t } = useI18n();
const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const payForm = useForm({ amount: '', method: 'cash' });
function pay() {
    payForm.post(route('app.suppliers.payments.store', props.supplier.id), {
        onSuccess: () => payForm.reset(),
    });
}

// Phase 47 — owner-only manual due (money owed is never auto-created from
// article intake; this is the one deliberate place it's recorded)
const dueSheet = ref(false);
const dueForm = useForm({ amount: '', memo: '' });
function addDue() {
    dueForm.post(route('app.suppliers.due', props.supplier.id), {
        onSuccess: () => { dueSheet.value = false; dueForm.reset(); },
    });
}

const productTotals = computed(() => ({
    intake: (props.products || []).reduce((s, p) => s + p.intake_value, 0),
    revenue: (props.products || []).reduce((s, p) => s + p.sold_revenue, 0),
    units: (props.products || []).reduce((s, p) => s + p.sold_units, 0),
}));

const methodLabel = { cash: 'pay.cash', bkash: 'pay.bkash', nagad: 'pay.nagad' };
</script>

<template>
    <Head :title="supplier.name" />
    <AppLayout active="suppliers">
        <div class="flex items-center gap-3 mb-4" style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <Link :href="route('app.suppliers')" style="color:var(--mut)">←</Link>
            <div class="pgttl" style="margin:0">{{ supplier.name }}</div>
        </div>
        <div class="pgsub">📞 {{ supplier.phone || t('due.noNumber') }}<template v-if="supplier.address"> • {{ supplier.address }}</template></div>

        <div class="f2" style="margin:16px 0">
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('supplier.currentPayable') }}</div>
                <div style="font-size:24px;font-weight:850;color:var(--rose)">{{ money(totals.payable) }}</div>
            </div>
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('supplier.totalPurchased') }}</div>
                <div style="font-size:24px;font-weight:850">{{ money(totals.purchased) }}</div>
            </div>
        </div>
        <div class="f2" style="margin-bottom:16px">
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('supplier.totalPaid') }}</div>
                <div style="font-size:20px;font-weight:800;color:var(--green)">{{ money(totals.paid) }}</div>
            </div>
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('supplier.totalReturned') }}</div>
                <div style="font-size:20px;font-weight:800">{{ money(totals.returned) }}</div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="field">
                <label>{{ t('supplier.payAmount') }}</label>
                <input v-model="payForm.amount" type="number" :placeholder="String(totals.payable || 0)">
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
        </div>

        <button v-if="isOwner" class="btn ghost" style="margin-bottom:20px" @click="dueSheet = true">{{ t('supplier.addDue') }}</button>

        <!-- Phase 47 — auto-derived from products.supplier_id (set at
             article entry) + lifetime sale_items sums: no manual entry -->
        <div class="sechead"><h2>{{ t('supplier.productsTitle') }}</h2></div>
        <div v-if="products?.length" class="card" style="margin-bottom:12px;display:flex;justify-content:space-around;text-align:center">
            <div><div style="font-size:11px;color:var(--mut)">{{ t('supplier.intakeApproxLabel') }}</div><b>{{ money(productTotals.intake) }}</b></div>
            <div><div style="font-size:11px;color:var(--mut)">{{ t('supplier.soldLabel') }}</div><b style="color:var(--green)">{{ money(productTotals.revenue) }} ({{ Math.round(productTotals.units) }})</b></div>
        </div>
        <div v-if="!products?.length" class="empty" style="padding:16px 0"><div class="big">👟</div>{{ t('supplier.noProducts') }}</div>
        <div v-for="p in products" :key="p.id" class="row" style="cursor:default">
            <div class="ava">{{ p.emoji || '📦' }}</div>
            <div class="mid">
                <b>{{ p.name }}</b>
                <span>{{ p.added_on }} • {{ t('supplier.productStock', { stock: p.stock }) }} • {{ t('supplier.intakeApprox', { amount: money(p.intake_value) }) }}</span>
                <span style="color:var(--green);font-size:11.5px">{{ t('supplier.productSold', { units: Math.round(p.sold_units), revenue: money(p.sold_revenue) }) }}</span>
            </div>
        </div>

        <div class="sechead"><h2>{{ t('supplier.purchaseHistory') }}</h2></div>
        <div v-if="!purchases.length" class="empty" style="padding:16px 0"><div class="big">📦</div>{{ t('supplier.noPurchasesYet') }}</div>
        <div v-for="p in purchases" :key="p.id" class="row" style="cursor:default">
            <div class="ava">{{ p.product?.emoji || '🧾' }}</div>
            <div class="mid">
                <b>{{ p.product?.name || p.memo || t('purchase.product') }}</b>
                <span>{{ p.date }} • {{ t(p.status === 'pending' ? 'purchase.statusPending' : 'purchase.statusReceived') }}</span>
            </div>
            <div class="end"><b>{{ money(p.amount) }}</b></div>
        </div>

        <div class="sechead"><h2>{{ t('supplier.returnHistory') }}</h2></div>
        <div v-if="!returns.length" class="empty" style="padding:16px 0"><div class="big">↩️</div>{{ t('supplier.noReturnsYet') }}</div>
        <div v-for="r in returns" :key="r.id" class="row" style="cursor:default">
            <div class="ava">{{ r.product?.emoji || '📦' }}</div>
            <div class="mid">
                <b>{{ r.product?.name }}</b>
                <span>{{ r.date }} • {{ r.qty }} {{ t('stock.pieces') }}<template v-if="r.reason"> • {{ r.reason }}</template></span>
            </div>
            <div class="end"><b>{{ money(r.amount) }}</b></div>
        </div>

        <div class="sechead"><h2>{{ t('supplier.paymentHistory') }}</h2></div>
        <div v-if="!payments.length" class="empty" style="padding:16px 0"><div class="big">💸</div>{{ t('supplier.noPaymentsYet') }}</div>
        <div v-for="pay_ in payments" :key="pay_.id" class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:12px;color:var(--mut)">{{ pay_.date }}<template v-if="pay_.user"> • {{ pay_.user.name }}</template> • {{ t(methodLabel[pay_.method] || 'pay.cash') }}</span>
                <b style="color:var(--green);font-size:15px">{{ money(pay_.amount) }}</b>
            </div>
            <div v-if="pay_.payable_before !== null && pay_.payable_after !== null" style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:var(--dim)">
                <span>{{ t('due.dueBefore') }}: {{ money(pay_.payable_before) }}</span>
                <span>→</span>
                <span>{{ t('due.dueAfter') }}: {{ money(pay_.payable_after) }}</span>
            </div>
        </div>
        <Sheet v-model="dueSheet" :title="t('supplier.addDue')">
            <div style="font-size:12px;color:var(--mut);margin-bottom:12px">{{ t('supplier.dueHint') }}</div>
            <div class="field">
                <label>{{ t('supplier.dueAmount') }}</label>
                <input v-model="dueForm.amount" type="number" min="1">
                <div v-if="dueForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ dueForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('supplier.dueMemo') }}</label>
                <input v-model="dueForm.memo">
                <div v-if="dueForm.errors.memo" style="color:var(--rose);font-size:12px;margin-top:6px">{{ dueForm.errors.memo }}</div>
            </div>
            <button class="btn" :disabled="dueForm.processing" @click="addDue">
                {{ dueForm.processing ? '...' : t('supplier.dueSubmit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="dueSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
