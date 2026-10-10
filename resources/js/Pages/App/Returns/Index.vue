<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';

const props = defineProps({ returnWindowDays: Number, products: Array });
const { t } = useI18n();
const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');

const money = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// --- search ---
const q = ref('');
const searching = ref(false);
const results = ref([]);
const searchError = ref('');

async function search() {
    if (!q.value.trim()) return;
    searching.value = true;
    searchError.value = '';
    results.value = [];
    selectedSale.value = null;
    try {
        const res = await fetch(route('app.returns.lookup') + '?q=' + encodeURIComponent(q.value.trim()), {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        results.value = data.sales || [];
    } catch (e) {
        searchError.value = 'খুঁজতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
    } finally {
        searching.value = false;
    }
}

// --- selected sale + lines ---
const selectedSale = ref(null);
const lineState = ref({}); // sale_item_id -> { qty, condition, checked }
const action = ref('refund'); // 'refund' | 'exchange'
const overrideWindow = ref(false);

function selectSale(sale) {
    selectedSale.value = sale;
    action.value = 'refund';
    overrideWindow.value = false;
    newItems.value = [];
    payments.value = [];
    resultSummary.value = null;
    const state = {};
    for (const item of sale.items) {
        state[item.id] = { qty: 0, condition: 'resalable', checked: false };
    }
    lineState.value = state;
}

const selectedLines = computed(() => {
    if (!selectedSale.value) return [];
    return selectedSale.value.items
        .filter((item) => lineState.value[item.id]?.checked && Number(lineState.value[item.id]?.qty) > 0)
        .map((item) => ({
            sale_item_id: item.id,
            qty: Number(lineState.value[item.id].qty),
            condition: lineState.value[item.id].condition,
            product_name: item.product_name,
            variant_label: item.variant_label,
            price: item.price,
        }));
});

// --- exchange: new items ---
const newItems = ref([]); // [{product_id, product_variant_id, qty, _name, _price}]
function addNewItemRow() {
    newItems.value.push({ product_id: '', product_variant_id: '', qty: 1 });
}
function removeNewItemRow(idx) {
    newItems.value.splice(idx, 1);
}
function productOf(id) {
    return props.products.find((p) => p.id === Number(id));
}
function variantPrice(row) {
    const p = productOf(row.product_id);
    if (!p) return 0;
    if (row.product_variant_id) {
        const v = p.variants.find((v) => v.id === Number(row.product_variant_id));
        return v ? v.price : p.price;
    }
    return p.price;
}
const newItemsTotal = computed(() => newItems.value.reduce((sum, row) => sum + variantPrice(row) * Number(row.qty || 0), 0));

const estimatedRefund = computed(() => {
    // client-side estimate only, for display before submit — the server
    // recomputes this authoritatively from the locked sale/sale_item rows
    if (!selectedSale.value) return 0;
    const subtotal = Number(selectedSale.value.subtotal) || 0;
    const discount = Number(selectedSale.value.discount) || 0;
    return selectedLines.value.reduce((sum, line) => {
        const item = selectedSale.value.items.find((i) => i.id === line.sale_item_id);
        if (!item) return sum;
        const lineTotal = item.price * item.qty - item.discount;
        const allocated = subtotal > 0 ? discount * (lineTotal / subtotal) : 0;
        const netPaidForLine = Math.max(0, lineTotal - allocated);
        const perUnit = item.qty > 0 ? netPaidForLine / item.qty : 0;
        return sum + perUnit * line.qty;
    }, 0);
});

const netSettlement = computed(() => newItemsTotal.value - estimatedRefund.value);

// --- payments (for an exchange price top-up) ---
const payments = ref([]);
function addPaymentRow() {
    payments.value.push({ method: 'cash', amount: 0 });
}

// --- submit ---
const submitting = ref(false);
const errorMsg = ref('');
const resultSummary = ref(null);

async function submit() {
    if (!selectedSale.value || !selectedLines.value.length) {
        errorMsg.value = 'অন্তত একটা আইটেম বাছাই করুন।';
        return;
    }
    submitting.value = true;
    errorMsg.value = '';

    const payload = {
        sale_id: selectedSale.value.id,
        action: action.value,
        lines: selectedLines.value.map((l) => ({ sale_item_id: l.sale_item_id, qty: l.qty, condition: l.condition })),
        override_window: overrideWindow.value,
    };
    if (action.value === 'exchange') {
        payload.new_items = newItems.value
            .filter((r) => r.product_id && Number(r.qty) > 0)
            .map((r) => ({ product_id: Number(r.product_id), product_variant_id: r.product_variant_id ? Number(r.product_variant_id) : null, qty: Number(r.qty) }));
        payload.payments = payments.value.filter((p) => Number(p.amount) > 0).map((p) => ({ method: p.method, amount: Number(p.amount) }));
    }

    try {
        const res = await fetchWithSessionRetry(route('app.returns.process'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) {
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || 'একটা সমস্যা হয়েছে।';
            return;
        }
        resultSummary.value = data;
        selectedSale.value = null;
        results.value = [];
        q.value = '';
    } catch (e) {
        errorMsg.value = 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।';
    } finally {
        submitting.value = false;
    }
}

function printMemo() {
    window.print();
}
</script>

<template>
    <Head :title="t('returns.pageTitle')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('returns.pageTitle') }}</div>

        <div v-if="!resultSummary">
            <div class="field">
                <label>{{ t('returns.searchPlaceholder') }}</label>
                <div style="display:flex;gap:8px">
                    <input v-model="q" @keyup.enter="search" :placeholder="t('returns.searchPlaceholder')">
                    <button class="btn" style="width:auto;padding:0 18px" :disabled="searching" @click="search">{{ t('returns.search') }}</button>
                </div>
            </div>

            <div v-if="searchError" style="color:var(--rose);font-size:13px">{{ searchError }}</div>

            <div v-if="results.length && !selectedSale">
                <div v-for="sale in results" :key="sale.id" class="row" style="width:100%;text-align:left;cursor:pointer" @click="selectSale(sale)">
                    <div class="ava">🧾</div>
                    <div class="mid">
                        <b>{{ sale.invoice_no }}</b>
                        <span>{{ sale.date }} • {{ sale.customer?.name || 'ওয়াক-ইন' }} • {{ money(sale.total) }}</span>
                    </div>
                    <div class="end">
                        <span v-if="!sale.within_window" style="color:var(--rose);font-size:12px">সময় শেষ</span>
                        <span v-else>›</span>
                    </div>
                </div>
            </div>
            <div v-else-if="q && !results.length && !searching" class="empty"><div class="big">🔎</div>{{ t('returns.noResults') }}</div>

            <div v-if="selectedSale" class="card" style="margin-top:16px">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <b>{{ selectedSale.invoice_no }}</b>
                    <button class="btn ghost" style="width:auto;padding:4px 12px;font-size:12px" @click="selectedSale = null">✕</button>
                </div>
                <div style="color:var(--mut);font-size:13px;margin-top:4px">{{ selectedSale.date }} • {{ money(selectedSale.total) }} • {{ selectedSale.customer?.name || 'ওয়াক-ইন' }}</div>

                <div v-if="!selectedSale.within_window" class="card" style="background:var(--roseSoft);margin-top:10px">
                    <div style="color:var(--rose);font-weight:700">{{ t('returns.windowExpired') }}</div>
                    <label v-if="isOwner" style="display:flex;gap:8px;align-items:center;margin-top:8px">
                        <input type="checkbox" v-model="overrideWindow"> {{ t('returns.ownerOverride') }}
                    </label>
                </div>

                <div style="margin-top:14px;font-weight:700">{{ t('returns.items') }}</div>
                <div v-for="item in selectedSale.items" :key="item.id" class="row" style="width:100%">
                    <div class="mid" style="flex:1">
                        <b>{{ item.product_name }}</b><span v-if="item.variant_label">{{ item.variant_label }}</span>
                        <span>{{ money(item.price) }} × {{ item.qty }} • {{ t('returns.returnableQty') }}: {{ item.returnable_qty }}</span>
                    </div>
                    <template v-if="item.returnable_qty > 0 && lineState[item.id]">
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="checkbox" v-model="lineState[item.id].checked">
                            <input type="number" v-model="lineState[item.id].qty" :max="item.returnable_qty" min="0" step="any" style="width:70px" :disabled="!lineState[item.id].checked">
                            <select v-model="lineState[item.id].condition" style="width:150px" :disabled="!lineState[item.id].checked">
                                <option value="resalable">{{ t('returns.conditionGood') }}</option>
                                <option value="damaged">{{ t('returns.conditionDamaged') }}</option>
                            </select>
                        </div>
                    </template>
                </div>

                <div style="margin-top:16px;display:flex;gap:10px">
                    <button class="btn" :class="{ ghost: action !== 'refund' }" style="flex:1" @click="action = 'refund'">{{ t('returns.actionRefund') }}</button>
                    <button class="btn" :class="{ ghost: action !== 'exchange' }" style="flex:1" @click="action = 'exchange'">{{ t('returns.actionExchange') }}</button>
                </div>

                <div v-if="action === 'exchange'" style="margin-top:14px">
                    <div style="font-weight:700">{{ t('returns.newItemsTitle') }}</div>
                    <div v-for="(row, idx) in newItems" :key="idx" class="f2" style="margin-top:8px;align-items:end">
                        <div class="field">
                            <label>পণ্য</label>
                            <select v-model="row.product_id">
                                <option value="">—</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>
                        <div class="field" v-if="productOf(row.product_id)?.variants?.length">
                            <label>সাইজ/রং</label>
                            <select v-model="row.product_variant_id">
                                <option value="">—</option>
                                <option v-for="v in productOf(row.product_id).variants" :key="v.id" :value="v.id">{{ v.label }} ({{ v.stock }})</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>পরিমাণ</label>
                            <div style="display:flex;gap:6px">
                                <input type="number" v-model="row.qty" min="1" step="1" style="width:70px">
                                <button class="btn ghost" style="width:auto;padding:0 10px" @click="removeNewItemRow(idx)">✕</button>
                            </div>
                        </div>
                    </div>
                    <button class="btn ghost" style="margin-top:8px" @click="addNewItemRow">{{ t('returns.addNewItem') }}</button>

                    <div class="card" style="margin-top:12px;background:var(--surface2)">
                        <div>নতুন আইটেমের মূল্য: {{ money(newItemsTotal) }}</div>
                        <div>আনুমানিক রিফান্ড ক্রেডিট: {{ money(estimatedRefund) }}</div>
                        <div style="font-weight:700">{{ netSettlement > 0 ? 'কাস্টমারের থেকে নিতে হবে' : netSettlement < 0 ? 'কাস্টমারকে ফেরত দিতে হবে' : 'সমান' }}: {{ money(Math.abs(netSettlement)) }}</div>
                    </div>

                    <div v-if="netSettlement > 0" style="margin-top:10px">
                        <div style="font-weight:700">{{ t('returns.collectPayment') }}</div>
                        <div v-for="(p, idx) in payments" :key="idx" class="f2" style="margin-top:6px">
                            <select v-model="p.method"><option value="cash">ক্যাশ</option><option value="bkash">বিকাশ</option><option value="nagad">নগদ</option></select>
                            <input type="number" v-model="p.amount" min="0" step="any">
                        </div>
                        <button class="btn ghost" style="margin-top:6px" @click="addPaymentRow">+ পেমেন্ট যোগ করুন</button>
                    </div>
                </div>

                <div v-if="action === 'refund'" class="card" style="margin-top:12px;background:var(--surface2)">
                    <div style="font-weight:700">{{ t('returns.totalRefund') }}: {{ money(estimatedRefund) }}</div>
                </div>

                <div v-if="errorMsg" style="color:var(--rose);margin-top:10px">{{ errorMsg }}</div>

                <button class="btn" style="margin-top:16px" :disabled="submitting || !selectedLines.length" @click="submit">
                    {{ submitting ? '...' : t('returns.submit') }}
                </button>
            </div>
        </div>

        <div v-else class="card" id="returnMemo">
            <div style="text-align:center;font-weight:800;font-size:18px">{{ t('returns.success') }}</div>
            <div style="margin-top:10px">আসল ইনভয়েস: <b>{{ resultSummary.sale.invoice_no }}</b></div>
            <div v-for="r in resultSummary.returns" :key="r.id">
                {{ r.condition === 'resalable' ? '✅' : '🗑️' }} {{ r.qty }} ইউনিট — {{ money(r.refund) }}
            </div>
            <div style="margin-top:8px;font-weight:700">{{ t('returns.totalRefund') }}: {{ money(resultSummary.total_refund) }}
                <span v-if="resultSummary.refund_due_portion > 0" style="color:var(--mut);font-size:12px">{{ t('returns.appliedToDue') }}</span>
            </div>
            <div v-if="resultSummary.exchange_sale" style="margin-top:8px">
                {{ t('returns.newInvoice') }}: <b>{{ resultSummary.exchange_sale.invoice_no }}</b> — {{ money(resultSummary.exchange_sale.total) }}
            </div>
            <div v-if="resultSummary.points_deducted" style="margin-top:6px;color:var(--mut);font-size:13px">লয়্যালটি পয়েন্ট কমানো হয়েছে: {{ resultSummary.points_deducted }}</div>

            <div class="no-print" style="margin-top:16px;display:flex;gap:10px">
                <button class="btn" @click="printMemo">{{ t('returns.print') }}</button>
                <button class="btn ghost" @click="resultSummary = null">{{ t('common.close') || 'বন্ধ' }}</button>
            </div>
        </div>
    </AppLayout>
</template>
