<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useToast } from '@/composables/useToast';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';
import { matchRentableProduct } from '@/support/rentalScan';

const props = defineProps({ rentals: Object, tab: String, products: Array, summary: Object });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

const statusLabel = (s) => ({
    active: t('rental.statusActive'), partially_returned: t('rental.statusPartiallyReturned'),
    returned: t('rental.statusReturned'), cancelled: t('rental.statusCancelled'),
}[s] || s);

// --- new rental sheet ---
const createSheet = ref(false);
const customerQuery = ref('');
const customerResults = ref([]);
const selectedCustomer = ref(null);
const rentalDate = ref(new Date().toISOString().slice(0, 10));
const expectedReturnDate = ref(new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10));
const securityDeposit = ref('');
const items = ref([]);
const advanceAmount = ref('');
const advanceMethod = ref('cash');
const submitting = ref(false);
const errorMsg = ref('');

async function searchCustomer() {
    if (customerQuery.value.trim().length < 2) { customerResults.value = []; return; }
    const res = await fetch(route('app.customers.search') + '?q=' + encodeURIComponent(customerQuery.value.trim()), { headers: { Accept: 'application/json' } });
    const data = await res.json();
    customerResults.value = data.results || [];
}
function pickCustomer(c) {
    selectedCustomer.value = c;
    customerResults.value = [];
    customerQuery.value = c.name + ' (' + c.phone + ')';
}

function addItemRow() {
    items.value.push({ product_id: '', product_variant_id: '', qty: 1, rent_price: 0 });
}
function removeItemRow(idx) {
    items.value.splice(idx, 1);
}
function productOf(id) {
    return props.products.find((p) => p.id === Number(id));
}
function onProductChange(row) {
    const p = productOf(row.product_id);
    row.rent_price = p ? p.rent_price : 0;
    row.product_variant_id = '';
}
const itemsTotal = computed(() => items.value.reduce((sum, row) => sum + Number(row.rent_price || 0) * Number(row.qty || 0), 0));

// --- scan to add a product (camera + hardware) — same barcode precedence
// (a variant's own barcode checked first) and continuous-scan behavior
// Returns/Index.vue already uses this session. props.products already
// carries the FULL rentable catalog (not paginated), so matching stays
// purely client-side, no separate lookup endpoint needed.
function addScannedItem(productId, variantId) {
    const existing = items.value.find((r) => Number(r.product_id) === productId && (variantId ? Number(r.product_variant_id) === variantId : !r.product_variant_id));
    if (existing) {
        existing.qty = Number(existing.qty || 0) + 1;
        return;
    }
    const p = productOf(productId);
    items.value = items.value.filter((r) => r.product_id); // drop the one still-blank placeholder row, if any
    items.value.push({ product_id: productId, product_variant_id: variantId || '', qty: 1, rent_price: p ? p.rent_price : 0 });
}

let lastScanCode = '';
let lastScanAt = 0;
function handleScan(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return;
    lastScanCode = decodedText;
    lastScanAt = now;
    if (navigator.vibrate) navigator.vibrate(40);
    const code = decodedText.trim();

    const match = matchRentableProduct(props.products, code);
    if (match.status === 'variant') {
        const p = productOf(match.productId);
        const v = p.variants.find((variant) => variant.id === match.variantId);
        addScannedItem(match.productId, match.variantId);
        onFeedback('✅ ' + p.name + ' (' + v.label + ')');
    } else if (match.status === 'product') {
        addScannedItem(match.productId, null);
        onFeedback('✅ ' + productOf(match.productId).name);
    } else if (match.status === 'needs_variant') {
        onFeedback('❌ ' + productOf(match.productId).name + ' — ' + t('rental.sizeColor'));
    } else {
        onFeedback('❌ ' + t('rental.notFoundOrNotRentable'));
    }
}

useHardwareScanner((code) => handleScan(code, (msg) => toast(msg)), () => createSheet.value);

const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
async function openScanner() {
    scannerOpen.value = true;
    scanHint.value = t('rental.scanHint');
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('rental-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            (text) => handleScan(text, (msg) => (scanHint.value = msg)),
            () => {},
        );
    } catch (e) {
        toast('❌ ' + t('rental.cameraNotFound'));
        closeScanner();
    }
}
async function closeScanner() {
    scannerOpen.value = false;
    if (html5Qrcode) {
        try { await html5Qrcode.stop(); html5Qrcode.clear(); } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}
onBeforeUnmount(() => { if (html5Qrcode) html5Qrcode.stop().catch(() => {}); });

function openCreate() {
    createSheet.value = true;
    customerQuery.value = '';
    customerResults.value = [];
    selectedCustomer.value = null;
    items.value = [{ product_id: '', product_variant_id: '', qty: 1, rent_price: 0 }];
    advanceAmount.value = '';
    securityDeposit.value = '';
    errorMsg.value = '';
}

let pendingUuid = null;
function nextUuid() {
    if (!pendingUuid) {
        pendingUuid = (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
    }
    return pendingUuid;
}

async function submitRental() {
    if (!selectedCustomer.value) { errorMsg.value = t('rental.selectCustomerError'); return; }
    const validItems = items.value.filter((r) => r.product_id && Number(r.qty) > 0);
    if (!validItems.length) { errorMsg.value = t('rental.addProductError'); return; }

    submitting.value = true;
    errorMsg.value = '';
    try {
        const res = await fetchWithSessionRetry(route('app.rentals.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify({
                customer_id: selectedCustomer.value.id,
                rental_date: rentalDate.value,
                expected_return_date: expectedReturnDate.value,
                security_deposit: securityDeposit.value || null,
                items: validItems.map((r) => ({ product_id: Number(r.product_id), product_variant_id: r.product_variant_id ? Number(r.product_variant_id) : null, qty: Number(r.qty), rent_price: Number(r.rent_price) })),
                advance_amount: Number(advanceAmount.value || 0),
                advance_method: advanceMethod.value,
                client_uuid: nextUuid(),
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || t('rental.genericError');
            return;
        }
        pendingUuid = null;
        toast(t('rental.createdToast', { invoice: data.rental.invoice_no }));
        window.location.href = route('app.rentals.show', data.rental.id);
    } catch (e) {
        errorMsg.value = t('rental.networkError');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="t('rental.title')" />
    <AppLayout active="rentals">
        <div class="pgttl">📅 {{ t('rental.title') }}</div>
        <div class="pgsub">{{ t('rental.subtitle') }}</div>

        <div class="grid2" style="margin-bottom:14px">
            <div class="stat gold"><div class="k">{{ t('rental.todayRental') }}</div><div class="v">{{ summary.todayCount }}</div><div class="h">{{ money(summary.todayAmount) }}</div></div>
            <div class="stat rose"><div class="k">{{ t('rental.overdueReturn') }}</div><div class="v">{{ summary.overdueCount }}</div><div class="h">{{ t('rental.items') }}</div></div>
            <div class="stat sky"><div class="k">{{ t('rental.activeRental') }}</div><div class="v">{{ summary.activeCount }}</div><div class="h">{{ t('rental.ongoing') }}</div></div>
            <div class="stat mint"><div class="k">{{ t('rental.totalIncome') }}</div><div class="v" style="font-size:15px">{{ money(summary.totalIncome) }}</div><div class="h">{{ t('rental.damagedUnits', { n: summary.damagedCount }) }}</div></div>
        </div>

        <button class="btn" style="margin-bottom:14px" @click="openCreate">{{ t('rental.newRental') }}</button>

        <div class="seg" style="margin-bottom:14px;overflow-x:auto">
            <Link :href="route('app.rentals.index', { tab: 'active' })" :class="{ on: tab === 'active' }">{{ t('rental.tabActive') }}</Link>
            <Link :href="route('app.rentals.index', { tab: 'overdue' })" :class="{ on: tab === 'overdue' }">{{ t('rental.tabOverdue') }}</Link>
            <Link :href="route('app.rentals.index', { tab: 'today' })" :class="{ on: tab === 'today' }">{{ t('rental.tabToday') }}</Link>
            <Link :href="route('app.rentals.index', { tab: 'upcoming' })" :class="{ on: tab === 'upcoming' }">{{ t('rental.tabUpcoming') }}</Link>
            <Link :href="route('app.rentals.index', { tab: 'returned' })" :class="{ on: tab === 'returned' }">{{ t('rental.tabReturned') }}</Link>
        </div>

        <Link v-for="r in rentals.data" :key="r.id" :href="route('app.rentals.show', r.id)" class="row">
            <div class="ava">📅</div>
            <div class="mid">
                <b>{{ r.invoice_no }}</b><span v-if="r.is_overdue" class="pill rose" style="margin-left:6px;font-size:10px">{{ t('rental.overdueBadge') }}</span>
                <span>{{ r.customer?.name }} • {{ r.rental_date }} → {{ r.expected_return_date }}</span>
            </div>
            <div class="end">
                <b>{{ money(r.total_rent_amount) }}</b>
                <span class="pill" :class="r.remaining_due > 0 ? 'rose' : 'mint'" style="margin-top:3px">{{ statusLabel(r.status) }}</span>
            </div>
        </Link>
        <div v-if="!rentals.data.length" class="empty"><div class="big">📅</div>{{ t('rental.noRentals') }}</div>
        <Pagination :links="rentals.links" />

        <Sheet v-model="createSheet" :title="t('rental.newRentalTitle')">
            <div class="field">
                <label>{{ t('rental.customer') }}</label>
                <input v-model="customerQuery" @input="searchCustomer" :placeholder="t('rental.customerPlaceholder')">
            </div>
            <div v-if="customerResults.length" class="card" style="margin-top:6px">
                <div v-for="c in customerResults" :key="c.id" class="row" style="width:100%;text-align:left;cursor:pointer" @click="pickCustomer(c)">
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }}</span></div>
                </div>
            </div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>{{ t('rental.rentalDate') }}</label><input type="date" v-model="rentalDate"></div>
                <div class="field"><label>{{ t('rental.returnDate') }}</label><input type="date" v-model="expectedReturnDate"></div>
            </div>
            <div class="field" style="margin-top:8px">
                <label>{{ t('rental.securityDeposit') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input type="number" v-model="securityDeposit" min="0" step="any">
            </div>

            <div style="font-weight:700;margin-top:14px">{{ t('rental.whatsBeingRented') }}</div>
            <div v-for="(row, idx) in items" :key="idx" class="f2" style="margin-top:8px;align-items:end">
                <div class="field">
                    <label>{{ t('rental.product') }}</label>
                    <select v-model="row.product_id" @change="onProductChange(row)">
                        <option value="">—</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ t('rental.availableCount', { n: p.available }) }})</option>
                    </select>
                </div>
                <div class="field" v-if="productOf(row.product_id)?.variants?.length">
                    <label>{{ t('rental.sizeColor') }}</label>
                    <select v-model="row.product_variant_id">
                        <option value="">—</option>
                        <option v-for="v in productOf(row.product_id).variants" :key="v.id" :value="v.id">{{ v.label }} ({{ v.available }})</option>
                    </select>
                </div>
                <div class="field">
                    <label>{{ t('rental.qtyRentPrice') }}</label>
                    <div style="display:flex;gap:6px">
                        <input type="number" v-model="row.qty" min="1" step="1" style="width:60px">
                        <input type="number" v-model="row.rent_price" min="0" step="any">
                        <button class="btn ghost" style="width:auto;padding:0 10px" @click="removeItemRow(idx)">✕</button>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button class="btn ghost" style="flex:1" @click="addItemRow">{{ t('rental.addMoreProduct') }}</button>
                <button class="btn ghost" style="width:auto;padding:0 16px" @click="openScanner">{{ t('rental.scanButton') }}</button>
            </div>

            <div class="card" style="margin-top:12px;background:var(--surface2)">
                <div style="font-weight:700">{{ t('rental.totalRent') }}: {{ money(itemsTotal) }}</div>
            </div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>{{ t('rental.advance') }}</label><input type="number" v-model="advanceAmount" min="0" step="any"></div>
                <div class="field">
                    <label>{{ t('rental.method') }}</label>
                    <select v-model="advanceMethod"><option value="cash">{{ t('pay.cash') }}</option><option value="bkash">{{ t('pay.bkash') }}</option><option value="nagad">{{ t('pay.nagad') }}</option></select>
                </div>
            </div>

            <div v-if="errorMsg" style="color:var(--rose);margin-top:10px">{{ errorMsg }}</div>

            <button class="btn" style="margin-top:16px" :disabled="submitting" @click="submitRental">
                {{ submitting ? '...' : t('rental.createButton') }}
            </button>
        </Sheet>

        <Sheet v-model="scannerOpen" :title="t('rental.scanTitle')">
            <div id="rental-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
            <div style="text-align:center;margin-top:10px;font-size:13px;color:var(--mut)">{{ scanHint }}</div>
            <div style="text-align:center;margin-top:4px;font-size:12px;color:var(--dim)">{{ t('rental.scanStaysOpen') }}</div>
            <div v-if="items.filter((r) => r.product_id).length" class="card" style="margin-top:10px;background:var(--surface2)">
                <div v-for="(row, idx) in items.filter((r) => r.product_id)" :key="idx" style="font-size:13px">
                    ✅ {{ productOf(row.product_id)?.name }}
                    <span v-if="row.product_variant_id">({{ productOf(row.product_id)?.variants?.find((v) => v.id === Number(row.product_variant_id))?.label }})</span>
                    × {{ qty(row.qty) }}
                </div>
            </div>
            <button class="btn ghost" style="margin-top:10px" @click="closeScanner">{{ t('rental.doneScan') }}</button>
        </Sheet>
    </AppLayout>
</template>
