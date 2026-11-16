<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';

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
    active: 'সক্রিয়', partially_returned: 'আংশিক ফেরত', returned: 'ফেরত সম্পন্ন', cancelled: 'বাতিল',
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
    if (!selectedCustomer.value) { errorMsg.value = 'কাস্টমার বাছাই করুন।'; return; }
    const validItems = items.value.filter((r) => r.product_id && Number(r.qty) > 0);
    if (!validItems.length) { errorMsg.value = 'অন্তত একটা পণ্য যোগ করুন।'; return; }

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
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || 'একটা সমস্যা হয়েছে।';
            return;
        }
        pendingUuid = null;
        toast('✅ রেন্টাল তৈরি হয়েছে: ' + data.rental.invoice_no);
        window.location.href = route('app.rentals.show', data.rental.id);
    } catch (e) {
        errorMsg.value = 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head title="ভাড়া ব্যবস্থাপনা" />
    <AppLayout active="rentals">
        <div class="pgttl">📅 ভাড়া ব্যবস্থাপনা</div>
        <div class="pgsub">সেল ও রেন্টাল — দুটোই একসাথে</div>

        <div class="grid2" style="margin-bottom:14px">
            <div class="stat gold"><div class="k">আজকের রেন্টাল</div><div class="v">{{ summary.todayCount }}</div><div class="h">{{ money(summary.todayAmount) }}</div></div>
            <div class="stat rose"><div class="k">ওভারডিউ ফেরত</div><div class="v">{{ summary.overdueCount }}</div><div class="h">আইটেম</div></div>
            <div class="stat sky"><div class="k">সক্রিয় রেন্টাল</div><div class="v">{{ summary.activeCount }}</div><div class="h">চলমান</div></div>
            <div class="stat mint"><div class="k">মোট রেন্টাল ইনকাম</div><div class="v" style="font-size:15px">{{ money(summary.totalIncome) }}</div><div class="h">{{ summary.damagedCount }} ক্ষতিগ্রস্ত ইউনিট</div></div>
        </div>

        <button class="btn" style="margin-bottom:14px" @click="openCreate">+ নতুন রেন্টাল</button>

        <div class="seg" style="margin-bottom:14px;overflow-x:auto">
            <Link :href="route('app.rentals.index', { tab: 'active' })" :class="{ on: tab === 'active' }">সক্রিয়</Link>
            <Link :href="route('app.rentals.index', { tab: 'overdue' })" :class="{ on: tab === 'overdue' }">ওভারডিউ</Link>
            <Link :href="route('app.rentals.index', { tab: 'today' })" :class="{ on: tab === 'today' }">আজকের</Link>
            <Link :href="route('app.rentals.index', { tab: 'upcoming' })" :class="{ on: tab === 'upcoming' }">আসন্ন ফেরত</Link>
            <Link :href="route('app.rentals.index', { tab: 'returned' })" :class="{ on: tab === 'returned' }">সম্পন্ন</Link>
        </div>

        <Link v-for="r in rentals.data" :key="r.id" :href="route('app.rentals.show', r.id)" class="row">
            <div class="ava">📅</div>
            <div class="mid">
                <b>{{ r.invoice_no }}</b><span v-if="r.is_overdue" class="pill rose" style="margin-left:6px;font-size:10px">ওভারডিউ</span>
                <span>{{ r.customer?.name }} • {{ r.rental_date }} → {{ r.expected_return_date }}</span>
            </div>
            <div class="end">
                <b>{{ money(r.total_rent_amount) }}</b>
                <span class="pill" :class="r.remaining_due > 0 ? 'rose' : 'mint'" style="margin-top:3px">{{ statusLabel(r.status) }}</span>
            </div>
        </Link>
        <div v-if="!rentals.data.length" class="empty"><div class="big">📅</div>কোনো রেন্টাল নেই</div>
        <Pagination :links="rentals.links" />

        <Sheet v-model="createSheet" title="নতুন রেন্টাল">
            <div class="field">
                <label>কাস্টমার</label>
                <input v-model="customerQuery" @input="searchCustomer" placeholder="নাম বা ফোন নম্বর">
            </div>
            <div v-if="customerResults.length" class="card" style="margin-top:6px">
                <div v-for="c in customerResults" :key="c.id" class="row" style="width:100%;text-align:left;cursor:pointer" @click="pickCustomer(c)">
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }}</span></div>
                </div>
            </div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>রেন্টাল তারিখ</label><input type="date" v-model="rentalDate"></div>
                <div class="field"><label>ফেরতের তারিখ</label><input type="date" v-model="expectedReturnDate"></div>
            </div>
            <div class="field" style="margin-top:8px">
                <label>সিকিউরিটি ডিপোজিট <span style="color:var(--dim);font-weight:400">ঐচ্ছিক</span></label>
                <input type="number" v-model="securityDeposit" min="0" step="any">
            </div>

            <div style="font-weight:700;margin-top:14px">যা ভাড়া দেওয়া হচ্ছে</div>
            <div v-for="(row, idx) in items" :key="idx" class="f2" style="margin-top:8px;align-items:end">
                <div class="field">
                    <label>পণ্য</label>
                    <select v-model="row.product_id" @change="onProductChange(row)">
                        <option value="">—</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.available }} পাওয়া যাবে)</option>
                    </select>
                </div>
                <div class="field" v-if="productOf(row.product_id)?.variants?.length">
                    <label>সাইজ/রং</label>
                    <select v-model="row.product_variant_id">
                        <option value="">—</option>
                        <option v-for="v in productOf(row.product_id).variants" :key="v.id" :value="v.id">{{ v.label }} ({{ v.available }})</option>
                    </select>
                </div>
                <div class="field">
                    <label>পরিমাণ × ভাড়া</label>
                    <div style="display:flex;gap:6px">
                        <input type="number" v-model="row.qty" min="1" step="1" style="width:60px">
                        <input type="number" v-model="row.rent_price" min="0" step="any">
                        <button class="btn ghost" style="width:auto;padding:0 10px" @click="removeItemRow(idx)">✕</button>
                    </div>
                </div>
            </div>
            <button class="btn ghost" style="margin-top:8px" @click="addItemRow">+ আরও পণ্য</button>

            <div class="card" style="margin-top:12px;background:var(--surface2)">
                <div style="font-weight:700">মোট ভাড়া: {{ money(itemsTotal) }}</div>
            </div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>অগ্রিম</label><input type="number" v-model="advanceAmount" min="0" step="any"></div>
                <div class="field">
                    <label>পদ্ধতি</label>
                    <select v-model="advanceMethod"><option value="cash">ক্যাশ</option><option value="bkash">বিকাশ</option><option value="nagad">নগদ</option></select>
                </div>
            </div>

            <div v-if="errorMsg" style="color:var(--rose);margin-top:10px">{{ errorMsg }}</div>

            <button class="btn" style="margin-top:16px" :disabled="submitting" @click="submitRental">
                {{ submitting ? '...' : 'রেন্টাল তৈরি করুন' }}
            </button>
        </Sheet>
    </AppLayout>
</template>
