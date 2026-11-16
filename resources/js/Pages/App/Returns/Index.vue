<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useToast } from '@/composables/useToast';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';
import { matchSaleReturnItem } from '@/support/rentalScan';

const props = defineProps({ returnWindowDays: Number, products: Array });
const { t } = useI18n();
const { toast } = useToast();
const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
const features = computed(() => page.props.features || []);
const hasMemoWhatsapp = computed(() => features.value.includes('memo_whatsapp'));
const shop = computed(() => page.props.shop || {});

const money = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

// qty comes back either as a decimal-cast model string ("1.000") or a plain
// number depending on which endpoint produced it — always show it trimmed
// of insignificant trailing zeros (1, not 1.000; 0.5, not 0.500).
const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

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
const returnReason = ref(''); // free-text, one per return event — see docs/FIX_PLAN.md's Phase 34 design note

function selectSale(sale) {
    selectedSale.value = sale;
    action.value = 'refund';
    overrideWindow.value = false;
    returnReason.value = '';
    newItems.value = [];
    newItemsDiscount.value = 0;
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
        return (v?.price ?? p.price) ?? 0;
    }
    return p.price ?? 0;
}
const newItemsTotal = computed(() => newItems.value.reduce((sum, row) => sum + variantPrice(row) * Number(row.qty || 0), 0));
const newItemsDiscount = ref(0);
const newItemsTotalAfterDiscount = computed(() => Math.max(0, newItemsTotal.value - Number(newItemsDiscount.value || 0)));

// --- exchange: scan to add a new item (camera + hardware), same barcode
// precedence as POS (a variant's own barcode is checked first) but looked
// up via returns.barcode, never pos.barcode — that endpoint ships full
// product cost, which this page deliberately never sends to the browser
// (see ReturnController::presentProductForPicker).
function addScannedItem(productId, variantId) {
    const existing = newItems.value.find((r) => Number(r.product_id) === productId && (variantId ? Number(r.product_variant_id) === variantId : !r.product_variant_id));
    if (existing) {
        existing.qty = Number(existing.qty || 0) + 1;
    } else {
        newItems.value.push({ product_id: productId, product_variant_id: variantId || '', qty: 1 });
    }
}

// --- scan to pick WHICH sold item is being returned (either action) —
// same continuous-scan, barcode-first pattern as Rentals' return screen
// (matchSaleReturnItem). Tried before falling through to "add a new
// exchange item" below, so one scanner button/handler serves both steps:
// a sold item's own barcode selects it for return; anything else (in
// exchange mode) is treated as a new item being given to the customer.
function applySaleReturnMatch(code) {
    if (!selectedSale.value) return null;
    const match = matchSaleReturnItem(selectedSale.value.items, code);
    if (match.status === 'not_found') return null;
    if (match.status === 'fully_returned') {
        return '❌ ' + match.item.product_name + ' — ইতিমধ্যে সম্পূর্ণ ফেরত নেওয়া হয়েছে';
    }
    const item = match.item;
    const line = lineState.value[item.id];
    line.checked = true;
    line.qty = Math.min(item.returnable_qty, Number(line.qty || 0) + 1);
    return '✅ ' + item.product_name + (item.variant_label ? ' (' + item.variant_label + ')' : '') + ' × ' + qty(line.qty);
}

let lastScanCode = '';
let lastScanAt = 0;
async function handleScan(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return;
    lastScanCode = decodedText;
    lastScanAt = now;
    if (navigator.vibrate) navigator.vibrate(40);
    const code = decodedText.trim();

    // Before any sale is picked, a scan IS the search — ReturnController::
    // lookup() already matches a sale by any item's barcode (this page's
    // own search placeholder already says "scan/type a barcode"), so just
    // run the same search this box's Enter key runs.
    if (!selectedSale.value) {
        q.value = code;
        onFeedback('🔎 ' + code);
        await search();
        return;
    }

    const returnMatch = applySaleReturnMatch(code);
    if (returnMatch) {
        onFeedback(returnMatch);
        return;
    }

    for (const p of props.products) {
        const v = p.variants?.find((variant) => variant.barcode === code);
        if (v && action.value === 'exchange') {
            addScannedItem(p.id, v.id);
            onFeedback('✅ ' + p.name + ' (' + v.label + ')');
            return;
        }
    }

    const product = props.products.find((p) => p.barcode === code);
    if (product && action.value === 'exchange') {
        if (product.variants?.length) {
            onFeedback('❌ ' + product.name + ' — সাইজ/রং বাছাই করুন');
            return;
        }
        addScannedItem(product.id, null);
        onFeedback('✅ ' + product.name);
        return;
    }

    // Didn't literally match a stored `barcode` column — most printed
    // labels actually encode a ZV/ZC/ZL short code (see BarcodeResolver)
    // that resolves to a product/variant id dynamically rather than being
    // a stored column value, so matchSaleReturnItem's plain string compare
    // above can never catch it. Resolve it the same way the exchange "new
    // item" picker already does, then match THIS sale's own items by id.
    try {
        const res = await fetch(route('app.returns.barcode', code), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (data.found) {
            const item = selectedSale.value.items.find((i) => i.product_id === data.product.id
                && (data.variant_id ? i.product_variant_id === data.variant_id : !i.product_variant_id));
            if (item) {
                if (item.returnable_qty <= 0) {
                    onFeedback('❌ ' + item.product_name + ' — ইতিমধ্যে সম্পূর্ণ ফেরত নেওয়া হয়েছে');
                    return;
                }
                const line = lineState.value[item.id];
                line.checked = true;
                line.qty = Math.min(item.returnable_qty, Number(line.qty || 0) + 1);
                onFeedback('✅ ' + item.product_name + (item.variant_label ? ' (' + item.variant_label + ')' : '') + ' × ' + qty(line.qty));
                return;
            }
            if (action.value !== 'exchange') {
                onFeedback('❌ এই বিলে এই বারকোডের পণ্য পাওয়া যায়নি');
                return;
            }
            props.products.push(data.product);
            if (data.variant_id) {
                const v = data.product.variants?.find((variant) => variant.id === data.variant_id);
                addScannedItem(data.product.id, data.variant_id);
                onFeedback('✅ ' + data.product.name + (v ? ' (' + v.label + ')' : ''));
            } else if (data.product.variants?.length) {
                onFeedback('❌ ' + data.product.name + ' — সাইজ/রং বাছাই করুন');
            } else {
                addScannedItem(data.product.id, null);
                onFeedback('✅ ' + data.product.name);
            }
        } else {
            onFeedback('❌ পণ্য পাওয়া যায়নি: ' + decodedText);
        }
    } catch (e) {
        onFeedback('❌ পণ্য পাওয়া যায়নি: ' + decodedText);
    }
}

// hardware (USB/Bluetooth keyboard-wedge) scanner — always live on this
// page: before a sale is picked it searches (same as this page's own
// barcode-capable search box), after a sale is picked it's handleScan's
// return-item/new-item matching above.
useHardwareScanner((code) => handleScan(code, (msg) => toast(msg)), true);

// camera scanner
const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
async function openScanner() {
    scannerOpen.value = true;
    scanHint.value = 'ক্যামেরার সামনে বারকোড ধরুন';
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('returns-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            (text) => handleScan(text, (msg) => (scanHint.value = msg)),
            () => {},
        );
    } catch (e) {
        toast('❌ ক্যামেরা পাওয়া যায়নি');
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

// Sales/Show.vue's "ফেরত / এক্সচেঞ্জ" button lands here with ?sale_id=123 —
// jump straight to that sale instead of making the cashier re-type/re-scan
// the invoice they were already looking at.
onMounted(async () => {
    const saleId = new URLSearchParams(window.location.search).get('sale_id');
    if (!saleId) return;
    try {
        const res = await fetch(route('app.returns.lookup') + '?sale_id=' + encodeURIComponent(saleId), {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (data.sales?.[0]) selectSale(data.sales[0]);
    } catch (e) { /* non-critical — cashier can still search manually */ }
});

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

const netSettlement = computed(() => newItemsTotalAfterDiscount.value - estimatedRefund.value);

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
        reason: returnReason.value.trim() || null,
    };
    if (action.value === 'exchange') {
        payload.new_items = newItems.value
            .filter((r) => r.product_id && Number(r.qty) > 0)
            .map((r) => ({ product_id: Number(r.product_id), product_variant_id: r.product_variant_id ? Number(r.product_variant_id) : null, qty: Number(r.qty) }));
        payload.new_items_discount = Number(newItemsDiscount.value || 0);
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

const conditionEmoji = (condition) => ({ resalable: '✅', damaged: '🗑', lost: '📭' })[condition] || '🗑';

function sendMemoWA() {
    const r = resultSummary.value;
    if (!r) return;
    const phone = r.sale.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    const shopName = shop.value?.name || 'Zaylotix POS';

    let lines = `*${shopName}*\n${'─'.repeat(16)}\nআসল ইনভয়েস: ${r.sale.invoice_no}\n${'─'.repeat(16)}\nযা ফেরত নেওয়া হলো:\n`;
    lines += r.returns.map((ret) => `${conditionEmoji(ret.condition)} ${qty(ret.qty)} ইউনিট — ${money(ret.refund)}`).join('\n');
    lines += `\nমোট ফেরত: ${money(r.total_refund)}`;

    if (r.exchange_sale) {
        lines += `\n${'─'.repeat(16)}\nযা দেওয়া হলো:\n`;
        lines += (r.exchange_sale.items || []).map((it) => `🔄 ${it.product_name}${it.variant_label ? ' (' + it.variant_label + ')' : ''} — ${qty(it.qty)} × ${money(it.price)}`).join('\n');
        const net = Number(r.exchange_sale.net_settlement || 0);
        lines += `\nদামের পার্থক্য: ${net > 0 ? 'আপনি দিয়েছেন ' + money(net) : net < 0 ? 'আপনি পেয়েছেন ' + money(Math.abs(net)) : 'কোনো পার্থক্য নেই'}`;
    }

    lines += `\n\nধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(lines), '_blank');
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
                    <button class="btn ghost" style="width:auto;padding:0 14px" @click="openScanner">📷</button>
                    <button class="btn" style="width:auto;padding:0 18px" :disabled="searching" @click="search">{{ t('returns.search') }}</button>
                </div>
            </div>

            <div v-if="searchError" style="color:var(--rose);font-size:13px">{{ searchError }}</div>

            <div v-if="results.length && !selectedSale">
                <div v-for="sale in results" :key="sale.id" class="row" style="width:100%;text-align:left;cursor:pointer" @click="selectSale(sale)">
                    <div class="ava">🧾</div>
                    <div class="mid">
                        <b>{{ sale.invoice_no }}</b><span v-if="sale.origin_shop_name" style="color:var(--gold2)"> 🏬 {{ sale.origin_shop_name }}</span>
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
                <div v-if="selectedSale.origin_shop_name" class="card" style="background:var(--goldSoft);margin-top:8px;padding:8px 12px">
                    🏬 {{ t('returns.crossBranchHint', { shop: selectedSale.origin_shop_name }) }}
                </div>

                <div v-if="!selectedSale.within_window" class="card" style="background:var(--roseSoft);margin-top:10px">
                    <div style="color:var(--rose);font-weight:700">{{ t('returns.windowExpired') }}</div>
                    <label v-if="isOwner" style="display:flex;gap:8px;align-items:center;margin-top:8px">
                        <input type="checkbox" v-model="overrideWindow"> {{ t('returns.ownerOverride') }}
                    </label>
                </div>

                <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center">
                    <div style="font-weight:700">{{ t('returns.items') }}</div>
                    <button class="btn ghost" style="width:auto;padding:4px 12px;font-size:13px" @click="openScanner">📷 স্ক্যান</button>
                </div>
                <div v-for="item in selectedSale.items" :key="item.id" class="row" style="width:100%">
                    <div class="mid" style="flex:1">
                        <b>{{ item.product_name }}</b><span v-if="item.variant_label">{{ item.variant_label }}</span>
                        <span>{{ money(item.price) }} × {{ qty(item.qty) }} • {{ t('returns.returnableQty') }}: {{ qty(item.returnable_qty) }}</span>
                    </div>
                    <template v-if="item.returnable_qty > 0 && lineState[item.id]">
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="checkbox" v-model="lineState[item.id].checked" @change="!lineState[item.id].checked && (lineState[item.id].qty = 0)">
                            <input type="number" v-model="lineState[item.id].qty" @input="Number(lineState[item.id].qty) > 0 && (lineState[item.id].checked = true)" :max="item.returnable_qty" min="0" step="any" style="width:70px">
                            <select v-model="lineState[item.id].condition" style="width:150px">
                                <option value="resalable">{{ t('returns.conditionGood') }}</option>
                                <option value="damaged">{{ t('returns.conditionDamaged') }}</option>
                                <option value="lost">{{ t('returns.conditionLost') }}</option>
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
                    <div style="display:flex;gap:8px;margin-top:8px">
                        <button class="btn ghost" style="flex:1" @click="addNewItemRow">{{ t('returns.addNewItem') }}</button>
                        <button class="btn ghost" style="width:auto;padding:0 16px" @click="openScanner">📷 নতুন পণ্য স্ক্যান</button>
                    </div>

                    <div class="field" style="margin-top:10px">
                        <label>নতুন আইটেমে ডিসকাউন্ট <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                        <input type="number" v-model="newItemsDiscount" min="0" :max="newItemsTotal" step="any">
                    </div>

                    <div class="card" style="margin-top:12px;background:var(--surface2)">
                        <div>আগে এই বিলে দেওয়া হয়েছিল: {{ money(selectedSale.tendered) }}</div>
                        <div>নতুন আইটেমের মূল্য: {{ money(newItemsTotal) }}</div>
                        <div v-if="Number(newItemsDiscount) > 0">ডিসকাউন্ট: − {{ money(newItemsDiscount) }}</div>
                        <div v-if="Number(newItemsDiscount) > 0" style="font-weight:700">ডিসকাউন্টের পর নতুন আইটেমের মূল্য: {{ money(newItemsTotalAfterDiscount) }}</div>
                        <div>আনুমানিক রিফান্ড ক্রেডিট (ফেরত দেওয়া আইটেমের জন্য): {{ money(estimatedRefund) }}</div>
                        <div style="font-weight:700;margin-top:4px">{{ netSettlement > 0 ? 'কাস্টমারের থেকে আরও নিতে হবে' : netSettlement < 0 ? 'কাস্টমারকে ফেরত দিতে হবে' : 'আর কিছু দিতে/নিতে হবে না' }}{{ netSettlement !== 0 ? ': ' + money(Math.abs(netSettlement)) : '' }}</div>
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

                <div class="field" style="margin-top:14px">
                    <label>{{ t('returns.reasonLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                    <input v-model="returnReason" :placeholder="t('returns.reasonPlaceholder')">
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
            <div style="margin-top:6px;font-weight:700">যা ফেরত নেওয়া হলো:</div>
            <div v-for="r in resultSummary.returns" :key="r.id">
                {{ conditionEmoji(r.condition) }} {{ qty(r.qty) }} ইউনিট — {{ money(r.refund) }}
            </div>
            <div style="margin-top:8px;font-weight:700">{{ t('returns.totalRefund') }}: {{ money(resultSummary.total_refund) }}
                <span v-if="resultSummary.refund_due_portion > 0" style="color:var(--mut);font-size:12px">{{ t('returns.appliedToDue') }}</span>
            </div>
            <template v-if="resultSummary.exchange_sale">
                <div style="margin-top:8px;font-weight:700">যা দেওয়া হলো:</div>
                <div v-for="(it, idx) in resultSummary.exchange_sale.items" :key="idx">
                    🔄 {{ it.product_name }}{{ it.variant_label ? ' ('+it.variant_label+')' : '' }} — {{ qty(it.qty) }} × {{ money(it.price) }}
                </div>
                <div v-if="resultSummary.exchange_sale.discount > 0" style="margin-top:4px">ডিসকাউন্ট দেওয়া হয়েছে: {{ money(resultSummary.exchange_sale.discount) }}</div>
                <div style="margin-top:4px">নতুন ইনভয়েস: <b>{{ resultSummary.exchange_sale.invoice_no }}</b> — {{ money(resultSummary.exchange_sale.total) }}</div>
                <div style="margin-top:4px;font-weight:700">
                    দামের পার্থক্য:
                    {{ resultSummary.exchange_sale.net_settlement > 0
                        ? 'কাস্টমার দিয়েছেন ' + money(resultSummary.exchange_sale.net_settlement)
                        : resultSummary.exchange_sale.net_settlement < 0
                            ? 'কাস্টমার পেয়েছেন ' + money(Math.abs(resultSummary.exchange_sale.net_settlement))
                            : 'কোনো পার্থক্য নেই' }}
                </div>
            </template>
            <div v-if="resultSummary.points_deducted" style="margin-top:6px;color:var(--mut);font-size:13px">লয়্যালটি পয়েন্ট কমানো হয়েছে: {{ resultSummary.points_deducted }}</div>

            <div class="no-print" style="margin-top:16px;display:flex;gap:10px">
                <button class="btn" @click="printMemo">{{ t('returns.print') }}</button>
                <button v-if="hasMemoWhatsapp" class="btn wa" @click="sendMemoWA">📤 WhatsApp</button>
                <button class="btn ghost" @click="resultSummary = null">{{ t('common.close') || 'বন্ধ' }}</button>
            </div>
        </div>

        <Sheet v-model="scannerOpen" title="বারকোড স্ক্যান করুন">
            <div id="returns-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
            <div style="text-align:center;margin-top:10px;font-size:13px;color:var(--mut)">{{ scanHint }}</div>
            <div style="text-align:center;margin-top:4px;font-size:12px;color:var(--dim)">ক্যামেরা চালু থাকবে — একটার পর একটা নতুন আইটেম স্ক্যান করতে থাকুন</div>
            <div v-if="newItems.length" class="card" style="margin-top:10px;background:var(--surface2)">
                <div v-for="(row, idx) in newItems" :key="idx" style="font-size:13px">
                    ✅ {{ productOf(row.product_id)?.name || '—' }}
                    <span v-if="row.product_variant_id">({{ productOf(row.product_id)?.variants?.find(v => v.id === Number(row.product_variant_id))?.label }})</span>
                    × {{ qty(row.qty) }}
                </div>
            </div>
            <button class="btn ghost" style="margin-top:10px" @click="closeScanner">{{ t('returns.doneScan') }}</button>
        </Sheet>
    </AppLayout>
</template>
