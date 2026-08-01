<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ shop: Object, products: Array, suppliers: Array });

const page = usePage();
const features = computed(() => page.props.features || []);
const user = computed(() => page.props.auth?.user);
const isOwner = computed(() => user.value?.role === 'owner');
const hasPerm = (key) => isOwner.value || (user.value?.permissions || []).includes(key);
const { t, lang } = useI18n();

// language is stored server-side per-user, plus mirrored to localStorage so
// the pre-login /login page (which has no user yet) still remembers it
function setLang(value) {
    localStorage.setItem('zaylotix_lang', value);
    router.patch(route('app.settings.lang'), { lang: value }, { preserveScroll: true });
}

// --- shop logo + receipt footer ---
const logoSheet = ref(false);
const logoForm = useForm({ logo: null });
function saveLogo() {
    logoForm.post(route('app.settings.logo'), { onSuccess: () => { logoSheet.value = false; logoForm.reset(); } });
}
const receiptFooterForm = useForm({ receipt_footer: props.shop?.receipt_footer || '' });
function saveReceiptFooter() {
    receiptFooterForm.patch(route('app.settings.receiptFooter'), { preserveScroll: true });
}
const binForm = useForm({ bin_no: props.shop?.bin_no || '' });
function saveBinNo() {
    binForm.patch(route('app.settings.binNo'), { preserveScroll: true });
}

// --- hardware barcode scanner (USB/Bluetooth keyboard-wedge) on/off ---
const hardwareScannerForm = useForm({ hardware_scanner_enabled: props.shop?.hardware_scanner_enabled ?? true });
function toggleHardwareScanner(value) {
    hardwareScannerForm.hardware_scanner_enabled = value;
    hardwareScannerForm.patch(route('app.settings.hardwareScanner'), { preserveScroll: true });
}

// --- payment gateway (bring-your-own-key) — owner only, lazy-loaded only
// when this sheet is actually opened, never shipped to a cashier session ---
const gatewaySheet = ref(false);
const gatewayProviders = ref([]);
const gatewayConfigured = ref({});
const gatewayActiveTab = ref('sslcommerz');
const GATEWAY_FIELDS = {
    sslcommerz: [
        { key: 'store_id', label: 'Store ID' },
        { key: 'store_passwd', label: 'Store Password', secret: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
    bkash: [
        { key: 'app_key', label: 'App Key' },
        { key: 'app_secret', label: 'App Secret', secret: true },
        { key: 'username', label: 'Username' },
        { key: 'password', label: 'Password', secret: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
    nagad: [
        { key: 'merchant_id', label: 'Merchant ID' },
        { key: 'merchant_number', label: 'Merchant Number' },
        { key: 'public_key', label: "Nagad's Public Key (PEM)", secret: true, textarea: true },
        { key: 'private_key', label: 'Your Private Key (PEM)', secret: true, textarea: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
};
const gatewayForm = useForm({});
function openGatewaySheet() {
    gatewaySheet.value = true;
    fetch(route('app.paymentGateways.index'), { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data) => { gatewayProviders.value = data.providers; gatewayConfigured.value = data.configured; });
}
function selectGatewayTab(provider) {
    gatewayActiveTab.value = provider;
    const fields = GATEWAY_FIELDS[provider];
    const blank = {};
    fields.forEach((f) => (blank[f.key] = f.bool ? true : ''));
    gatewayForm.defaults(blank);
    gatewayForm.reset();
}
selectGatewayTab('sslcommerz');
function saveGateway() {
    gatewayForm.post(route('app.paymentGateways.store', gatewayActiveTab.value), {
        preserveScroll: true,
        onSuccess: () => openGatewaySheet(), // refresh the configured/masked summary
    });
}
function toggleGatewayActive(provider, isActive) {
    router.patch(route('app.paymentGateways.toggle', provider), { is_active: isActive }, {
        preserveScroll: true, onSuccess: () => openGatewaySheet(),
    });
}
function disconnectGateway(provider) {
    if (!confirm(`${provider} সংযোগ বিচ্ছিন্ন করবেন?`)) return;
    router.delete(route('app.paymentGateways.destroy', provider), {
        preserveScroll: true, onSuccess: () => openGatewaySheet(),
    });
}

// --- purchase (stock-in from supplier) ---
const purchaseSheet = ref(false);
const purchaseAlsoStock = ref(false);
const purchaseForm = useForm({ supplier: '', supplier_id: '', memo: '', amount: '', method: 'cash', status: 'received', product_id: '', qty: '', cost: '', batch_no: '', expiry_date: '' });
function savePurchase() {
    if (!purchaseAlsoStock.value) { purchaseForm.product_id = ''; purchaseForm.qty = ''; purchaseForm.cost = ''; purchaseForm.batch_no = ''; purchaseForm.expiry_date = ''; }
    purchaseForm.post(route('app.purchases.store'), {
        onSuccess: () => { purchaseSheet.value = false; purchaseForm.reset(); purchaseAlsoStock.value = false; },
    });
}

const hasBatchTracking = computed(() => features.value.includes('batch_tracking'));
function batchesOf(productId) {
    return props.products.find((p) => p.id === productId)?.batches || [];
}
function batchLabel(b) {
    const expiry = b.expiry_date ? ` — ${b.expiry_date}` : '';
    const no = b.batch_no ? ` (${b.batch_no})` : '';
    return `${b.qty}× ${no}${expiry}`;
}

// --- damage ---
const damageSheet = ref(false);
const damageForm = useForm({ product_id: '', product_batch_id: '', qty: '', reason: '' });
function saveDamage() {
    damageForm.post(route('app.damages.store'), { onSuccess: () => { damageSheet.value = false; damageForm.reset(); } });
}

// --- return ---
const returnSheet = ref(false);
const returnForm = useForm({ product_id: '', product_batch_id: '', qty: '', refund: '', phone: '' });
function saveReturn() {
    returnForm.post(route('app.returns.store'), { onSuccess: () => { returnSheet.value = false; returnForm.reset(); } });
}

// --- supplier return (damaged/expired stock back to supplier) ---
const supplierReturnSheet = ref(false);
const supplierReturnForm = useForm({ supplier_id: '', supplier: '', product_id: '', qty: '', reason: '', settlement_method: 'payable', amount: '' });
function saveSupplierReturn() {
    supplierReturnForm.post(route('app.supplierReturns.store'), { onSuccess: () => { supplierReturnSheet.value = false; supplierReturnForm.reset(); } });
}

// --- stock count ---
const countSheet = ref(false);
const counts = ref({});
const countSubmitting = ref(false);
function applyCount() {
    const payload = Object.entries(counts.value)
        .filter(([, v]) => v !== '' && v !== null && v !== undefined)
        .map(([product_id, counted]) => ({ product_id: Number(product_id), counted: Number(counted) }));

    if (!payload.length) return;

    countSubmitting.value = true;
    router.post(route('app.stockCount.store'), { counts: payload }, {
        onSuccess: () => { countSheet.value = false; counts.value = {}; },
        onFinish: () => (countSubmitting.value = false),
    });
}

// --- export ---
const exportSheet = ref(false);
function download(kind, fmt) {
    window.open(route('app.export', kind) + '?format=' + fmt, '_blank');
}

function logout() {
    if (!confirm(t('common.logoutConfirm'))) return;
    router.post(route('app.logout'));
}

// the desktop sidebar links straight to a specific action here (e.g.
// ?open=cashier) instead of always landing on the plain "আরো" list —
// open that one sheet automatically so the click actually goes somewhere
onMounted(() => {
    const open = new URLSearchParams(window.location.search).get('open');
    if (!open) return;
    ({
        logo: () => (logoSheet.value = true),
        purchase: () => (purchaseSheet.value = true),
        damage: () => (damageSheet.value = true),
        return: () => (returnSheet.value = true),
        supplierReturn: () => (supplierReturnSheet.value = true),
        count: () => (countSheet.value = true),
        export: () => (exportSheet.value = true),
    }[open]?.());
});
</script>

<template>
    <Head :title="t('more.title')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('more.title') }}</div>
        <div class="pgsub">{{ shop?.name }} • {{ shop?.phone }}</div>

        <template v-if="hasPerm('sales_history') || (hasPerm('barcode_labels') && features.includes('barcode_printing')) || (hasPerm('pos') && features.includes('restaurant_tables')) || (hasPerm('promotions') && features.includes('promotions')) || (hasPerm('pos') && features.includes('quotations'))">
            <div class="sechead"><h2>{{ t('more.sectionSales') }}</h2></div>
            <Link v-if="hasPerm('pos') && features.includes('restaurant_tables')" :href="route('app.restaurant.tables.index')" class="row">
                <div class="ava">🍽️</div><div class="mid"><b>{{ t('nav.restaurant') }}</b><span>{{ t('restaurant.tablesSubtitle') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('sales_history')" :href="route('app.sales')" class="row">
                <div class="ava">🧾</div><div class="mid"><b>{{ t('nav.salesHistory') }}</b><span>{{ t('more.salesHistorySub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('barcode_labels') && features.includes('barcode_printing')" :href="route('app.barcodeLabels.index')" class="row">
                <div class="ava">🏷️</div><div class="mid"><b>{{ t('nav.barcode') }}</b><span>{{ t('more.barcodeSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('promotions') && features.includes('promotions')" :href="route('app.promotions.index')" class="row">
                <div class="ava">🎁</div><div class="mid"><b>{{ t('nav.promotions') }}</b><span>{{ t('promotion.subtitle') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('pos') && features.includes('quotations')" :href="route('app.quotations.index')" class="row">
                <div class="ava">📋</div><div class="mid"><b>{{ t('nav.quotations') }}</b><span>{{ t('quotation.subtitle') }}</span></div><div class="end">›</div>
            </Link>
        </template>

        <template v-if="hasPerm('customers')">
            <div class="sechead"><h2>{{ t('more.sectionCustomers') }}</h2></div>
            <Link :href="route('app.customers')" class="row">
                <div class="ava">👥</div><div class="mid"><b>{{ t('more.customerList') }}</b><span>{{ t('more.customerListSub') }}</span></div><div class="end">›</div>
            </Link>
        </template>

        <div class="sechead"><h2>{{ t('more.sectionShop') }}</h2></div>
        <div class="row" style="cursor:default">
            <div class="ava">🌐</div>
            <div class="mid"><b>{{ t('more.language') }}</b><span>{{ t('more.languageSub') }}</span></div>
            <div class="end">
                <div class="seg" style="margin:0">
                    <button :class="{ on: lang === 'bn' }" @click="setLang('bn')">{{ t('more.langSwitch.bn') }}</button>
                    <button :class="{ on: lang === 'en' }" @click="setLang('en')">{{ t('more.langSwitch.en') }}</button>
                </div>
            </div>
        </div>
        <button v-if="hasPerm('settings')" class="row" style="width:100%;text-align:left" @click="logoSheet = true">
            <div class="ava">{{ shop?.logo_url ? '🖼️' : '➕' }}</div>
            <div class="mid"><b>{{ t('more.shopLogo') }}</b><span>{{ t('more.shopLogoSub') }}</span></div><div class="end">›</div>
        </button>
        <button v-if="isOwner" class="row" style="width:100%;text-align:left" @click="openGatewaySheet">
            <div class="ava">💳</div>
            <div class="mid"><b>{{ t('more.paymentGateway') }}</b><span>{{ t('more.paymentGatewaySub') }}</span></div><div class="end">›</div>
        </button>
        <div v-if="hasPerm('settings')" class="row" style="cursor:default">
            <div class="ava">🔌</div>
            <div class="mid"><b>{{ t('more.hardwareScanner') }}</b><span>{{ t('more.hardwareScannerSub') }}</span></div>
            <div class="end">
                <div class="seg" style="margin:0">
                    <button :class="{ on: hardwareScannerForm.hardware_scanner_enabled }" @click="toggleHardwareScanner(true)">{{ t('more.on') }}</button>
                    <button :class="{ on: !hardwareScannerForm.hardware_scanner_enabled }" @click="toggleHardwareScanner(false)">{{ t('more.off') }}</button>
                </div>
            </div>
        </div>
        <Link v-if="isOwner && features.includes('cashier_management')" :href="route('app.staff.index')" class="row">
            <div class="ava">👤</div>
            <div class="mid"><b>{{ t('nav.cashier') }}</b><span>{{ t('more.cashierSub') }}</span></div><div class="end">›</div>
        </Link>
        <Link v-if="isOwner && features.includes('activity_log')" :href="route('app.activity')" class="row">
            <div class="ava">📋</div><div class="mid"><b>{{ t('nav.activity') }}</b><span>{{ t('activity.subtitle') }}</span></div><div class="end">›</div>
        </Link>
        <Link :href="route('app.help')" class="row">
            <div class="ava">❓</div><div class="mid"><b>{{ t('nav.help') }}</b><span>{{ t('more.helpSub') }}</span></div><div class="end">›</div>
        </Link>
        <Link v-if="isOwner && features.includes('hr_payroll')" :href="route('app.employees.index')" class="row">
            <div class="ava">👥</div><div class="mid"><b>{{ t('nav.employees') }}</b><span>{{ t('more.hrSub') }}</span></div><div class="end">›</div>
        </Link>
        <Link v-if="isOwner && features.includes('hr_payroll')" :href="route('app.attendance.index')" class="row">
            <div class="ava">📅</div><div class="mid"><b>{{ t('nav.attendance') }}</b><span>{{ t('att.subtitle') }}</span></div><div class="end">›</div>
        </Link>
        <Link v-if="isOwner && features.includes('hr_payroll')" :href="route('app.payroll.index')" class="row">
            <div class="ava">💰</div><div class="mid"><b>{{ t('nav.payroll') }}</b><span>{{ t('pay.subtitle') }}</span></div><div class="end">›</div>
        </Link>

        <template v-if="(hasPerm('accounts') && features.includes('accounts')) || (hasPerm('reports') && features.includes('reports')) || (hasPerm('expenses') && features.includes('expenses'))">
            <div class="sechead"><h2>{{ t('more.sectionAccounts') }}</h2></div>
            <Link v-if="hasPerm('accounts') && features.includes('accounts')" :href="route('app.accounts')" class="row">
                <div class="ava">💼</div><div class="mid"><b>{{ t('nav.accounts') }}</b><span>{{ t('more.accountsSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('accounts') && features.includes('accounts')" :href="route('app.cashLedger.index')" class="row">
                <div class="ava">💵</div><div class="mid"><b>{{ t('nav.cashLedger') }}</b><span>{{ t('more.cashLedgerSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('accounts') && features.includes('accounts')" :href="route('app.loans.index')" class="row">
                <div class="ava">🤲</div><div class="mid"><b>{{ t('nav.loans') }}</b><span>{{ t('more.loansSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="isOwner && features.includes('partners')" :href="route('app.partners.index')" class="row">
                <div class="ava">🤝</div><div class="mid"><b>{{ t('nav.partners') }}</b><span>{{ t('more.partnersSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('reports') && features.includes('reports')" :href="route('app.reports')" class="row">
                <div class="ava">📊</div><div class="mid"><b>{{ t('more.reportsTitle') }}</b><span>{{ t('more.reportsSub') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('expenses') && features.includes('expenses')" :href="route('app.expenses')" class="row">
                <div class="ava">💸</div><div class="mid"><b>{{ t('more.expensesTitle') }}</b><span>{{ t('more.expensesSub') }}</span></div><div class="end">›</div>
            </Link>
        </template>

        <template v-if="(hasPerm('stock_count') && features.includes('stock_count')) || (hasPerm('purchases') && features.includes('purchases')) || (hasPerm('purchases') && features.includes('suppliers')) || (hasPerm('stock') && features.includes('serial_tracking')) || (hasPerm('damages') && features.includes('damages')) || (hasPerm('returns') && features.includes('returns'))">
            <div class="sechead"><h2>{{ t('more.sectionStock') }}</h2></div>
            <button v-if="hasPerm('stock_count') && features.includes('stock_count')" class="row" style="width:100%;text-align:left" @click="countSheet = true">
                <div class="ava">🔢</div><div class="mid"><b>{{ t('more.stockCountTitle') }}</b><span>{{ t('more.stockCountSub') }}</span></div><div class="end">›</div>
            </button>
            <button v-if="hasPerm('purchases') && features.includes('purchases')" class="row" style="width:100%;text-align:left" @click="purchaseSheet = true">
                <div class="ava">🚚</div><div class="mid"><b>{{ t('more.purchaseTitle') }}</b><span>{{ t('more.purchaseSub') }}</span></div><div class="end">›</div>
            </button>
            <Link v-if="hasPerm('purchases') && features.includes('suppliers')" :href="route('app.suppliers')" class="row">
                <div class="ava">🏭</div><div class="mid"><b>{{ t('nav.suppliers') }}</b><span>{{ t('supplier.subtitle') }}</span></div><div class="end">›</div>
            </Link>
            <Link v-if="hasPerm('stock') && features.includes('serial_tracking')" :href="route('app.serials.index')" class="row">
                <div class="ava">📱</div><div class="mid"><b>{{ t('nav.serials') }}</b><span>{{ t('serial.subtitle') }}</span></div><div class="end">›</div>
            </Link>
            <button v-if="hasPerm('damages') && features.includes('damages')" class="row" style="width:100%;text-align:left" @click="damageSheet = true">
                <div class="ava">🗑️</div><div class="mid"><b>{{ t('more.damageTitle') }}</b><span>{{ t('more.damageSub') }}</span></div><div class="end">›</div>
            </button>
            <button v-if="hasPerm('returns') && features.includes('returns')" class="row" style="width:100%;text-align:left" @click="returnSheet = true">
                <div class="ava">↩️</div><div class="mid"><b>{{ t('more.returnTitle') }}</b><span>{{ t('more.returnSub') }}</span></div><div class="end">›</div>
            </button>
            <button v-if="hasPerm('purchases') && features.includes('suppliers')" class="row" style="width:100%;text-align:left" @click="supplierReturnSheet = true">
                <div class="ava">📦</div><div class="mid"><b>{{ t('more.supplierReturnTitle') }}</b><span>{{ t('more.supplierReturnSub') }}</span></div><div class="end">›</div>
            </button>
        </template>

        <template v-if="hasPerm('export') && features.includes('export')">
            <div class="sechead"><h2>{{ t('more.sectionExport') }}</h2></div>
            <button class="row" style="width:100%;text-align:left" @click="exportSheet = true">
                <div class="ava">📤</div><div class="mid"><b>{{ t('more.exportTitle') }}</b><span>{{ t('more.exportSub') }}</span></div><div class="end">›</div>
            </button>
        </template>

        <button class="btn ghost" style="margin-top:18px;color:var(--rose);border-color:var(--roseSoft)" @click="logout">🚪 {{ t('common.logout') }}</button>

        <div style="text-align:center;margin-top:24px;padding:16px 0 8px;border-top:1px solid var(--line)">
            <div style="font-size:12.5px;color:var(--mut)">A <a href="https://zaylotix.com/" target="_blank" rel="noopener" style="color:#7C3AED;font-weight:800;text-decoration:none">Zaylotix →</a> product</div>
            <div style="font-size:12.5px;color:var(--mut);margin-top:5px">Owner: <a href="https://khaledbinislam.com/" target="_blank" rel="noopener" style="color:var(--green);font-weight:800;text-decoration:none">Khaled Bin Islam →</a></div>
            <div style="font-size:12.5px;color:var(--mut);margin-top:5px">Contact: <a href="tel:01979894356" style="color:var(--gold);font-weight:800;text-decoration:none">01979894356</a></div>
        </div>

        <!-- shop logo -->
        <Sheet v-model="logoSheet" :title="t('logo.sheetTitle')" :subtitle="t('logo.sheetSub')">
            <div v-if="shop?.logo_url" style="text-align:center;margin-bottom:14px">
                <img :src="shop.logo_url" style="max-width:140px;border-radius:12px;border:1px solid var(--line)">
            </div>
            <div class="field">
                <input type="file" accept="image/*" @change="logoForm.logo = $event.target.files[0]">
                <div v-if="logoForm.errors.logo" style="color:var(--rose);font-size:12px;margin-top:6px">{{ logoForm.errors.logo }}</div>
            </div>
            <button class="btn" :disabled="logoForm.processing || !logoForm.logo" @click="saveLogo">
                {{ logoForm.processing ? t('logo.uploading') : t('logo.upload') }}
            </button>

            <div class="hr"></div>
            <div class="field">
                <label>{{ t('logo.receiptFooterLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="receiptFooterForm.receipt_footer" :placeholder="t('logo.receiptFooterPlaceholder')" maxlength="255">
                <div v-if="receiptFooterForm.errors.receipt_footer" style="color:var(--rose);font-size:12px;margin-top:6px">{{ receiptFooterForm.errors.receipt_footer }}</div>
            </div>
            <button class="btn sm ghost" style="width:100%" :disabled="receiptFooterForm.processing" @click="saveReceiptFooter">{{ receiptFooterForm.processing ? '...' : t('stock.save') }}</button>

            <div class="hr"></div>
            <div class="field">
                <label>{{ t('logo.binLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="binForm.bin_no" :placeholder="t('logo.binPlaceholder')" maxlength="50">
                <div v-if="binForm.errors.bin_no" style="color:var(--rose);font-size:12px;margin-top:6px">{{ binForm.errors.bin_no }}</div>
            </div>
            <button class="btn sm ghost" style="width:100%" :disabled="binForm.processing" @click="saveBinNo">{{ binForm.processing ? '...' : t('stock.save') }}</button>

            <button class="btn ghost" style="margin-top:10px" @click="logoSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- payment gateway (bring-your-own-key) -->
        <Sheet v-model="gatewaySheet" :title="t('more.paymentGateway')" :subtitle="t('more.paymentGatewaySub')">
            <div class="seg" style="margin-bottom:14px">
                <button v-for="p in ['sslcommerz', 'bkash', 'nagad']" :key="p" :class="{ on: gatewayActiveTab === p }" @click="selectGatewayTab(p)">{{ p }}</button>
            </div>

            <div v-if="gatewayConfigured[gatewayActiveTab]" class="card" style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <b>✅ {{ t('more.gatewayConnected') }}</b>
                        <div style="color:var(--mut);font-size:12px;margin-top:2px">{{ gatewayConfigured[gatewayActiveTab].masked_summary }}</div>
                    </div>
                    <div class="seg" style="margin:0">
                        <button :class="{ on: gatewayConfigured[gatewayActiveTab].is_active }" @click="toggleGatewayActive(gatewayActiveTab, true)">{{ t('more.on') }}</button>
                        <button :class="{ on: !gatewayConfigured[gatewayActiveTab].is_active }" @click="toggleGatewayActive(gatewayActiveTab, false)">{{ t('more.off') }}</button>
                    </div>
                </div>
                <button class="btn sm ghost" style="width:100%;margin-top:10px;color:var(--rose);border-color:var(--rose)" @click="disconnectGateway(gatewayActiveTab)">{{ t('more.gatewayDisconnect') }}</button>
            </div>
            <div v-else style="font-size:12.5px;color:var(--dim);margin-bottom:12px">{{ t('more.gatewayNotConnectedHint') }}</div>

            <div v-for="f in GATEWAY_FIELDS[gatewayActiveTab]" :key="f.key" class="field">
                <label v-if="!f.bool">{{ f.label }}</label>
                <label v-if="f.bool" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="gatewayForm[f.key]" type="checkbox" style="width:auto">
                    {{ f.label }}
                </label>
                <textarea v-else-if="f.textarea" v-model="gatewayForm[f.key]" rows="4" style="font-family:monospace;font-size:11px"></textarea>
                <input v-else-if="!f.bool" v-model="gatewayForm[f.key]" :type="f.secret ? 'password' : 'text'">
                <div v-if="gatewayForm.errors[f.key]" style="color:var(--rose);font-size:12px;margin-top:6px">{{ gatewayForm.errors[f.key] }}</div>
            </div>
            <div style="font-size:11px;color:var(--dim);margin-bottom:12px">{{ t('more.gatewaySecretHint') }}</div>
            <button class="btn" :disabled="gatewayForm.processing" @click="saveGateway">{{ gatewayForm.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="gatewaySheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- purchase -->
        <Sheet v-model="purchaseSheet" :title="t('purchase.sheetTitle')">
            <div v-if="features.includes('suppliers') && suppliers?.length" class="field">
                <label>{{ t('supplier.title') }}</label>
                <select v-model="purchaseForm.supplier_id">
                    <option value="">{{ t('purchase.supplierFreeText') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="field"><label>{{ t('purchase.supplier') }}</label><input v-model="purchaseForm.supplier"></div>
            <div class="field"><label>{{ t('purchase.memo') }}</label><input v-model="purchaseForm.memo" :placeholder="t('purchase.memoPlaceholder')"></div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('purchase.amount') }}</label>
                    <input v-model="purchaseForm.amount" type="number">
                    <div v-if="purchaseForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ purchaseForm.errors.amount }}</div>
                </div>
                <div class="field"><label>{{ t('purchase.method') }}</label>
                    <select v-model="purchaseForm.method"><option value="cash">{{ t('pay.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option><option value="credit">{{ t('pay.credit') }}</option></select>
                </div>
            </div>

            <div class="field">
                <label>{{ t('purchase.statusLabel') }}</label>
                <div class="seg">
                    <button type="button" :class="{ on: purchaseForm.status === 'received' }" @click="purchaseForm.status = 'received'">{{ t('purchase.statusReceived') }}</button>
                    <button type="button" :class="{ on: purchaseForm.status === 'pending' }" @click="purchaseForm.status = 'pending'">{{ t('purchase.statusPending') }}</button>
                </div>
                <div style="color:var(--dim);font-size:12px;margin-top:6px">{{ purchaseForm.status === 'pending' ? t('purchase.statusPendingHint') : t('purchase.statusReceivedHint') }}</div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin:10px 0">
                <input type="checkbox" v-model="purchaseAlsoStock" style="width:auto">
                {{ t('purchase.alsoAddStock') }}
            </label>
            <template v-if="purchaseAlsoStock">
                <div class="field">
                    <label>{{ t('purchase.product') }}</label>
                    <select v-model="purchaseForm.product_id">
                        <option value="">{{ t('stock.selectPlaceholder') }}</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                    </select>
                </div>
                <div class="f2">
                    <div class="field">
                        <label>{{ t('purchase.qty') }}</label>
                        <input v-model="purchaseForm.qty" type="number">
                        <div v-if="purchaseForm.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ purchaseForm.errors.qty }}</div>
                    </div>
                    <div class="field">
                        <label>{{ t('purchase.unitCost') }} <span style="color:var(--dim);font-weight:400">{{ t('purchase.unitCostHint') }}</span></label>
                        <input v-model="purchaseForm.cost" type="number">
                    </div>
                </div>
                <div v-if="features.includes('batch_tracking')" class="f2">
                    <div class="field"><label>{{ t('stock.batchNo') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="purchaseForm.batch_no"></div>
                    <div class="field"><label>{{ t('stock.expiryDate') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="purchaseForm.expiry_date" type="date"></div>
                </div>
            </template>

            <button class="btn" :disabled="purchaseForm.processing" @click="savePurchase">
                {{ purchaseForm.processing ? '...' : t('purchase.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="purchaseSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- damage -->
        <Sheet v-model="damageSheet" :title="t('damage.sheetTitle')">
            <div class="field">
                <label>{{ t('damage.selectProduct') }}</label>
                <select v-model="damageForm.product_id">
                    <option :value="''">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} — {{ t('damage.stockLabel') }}: {{ p.stock }}</option>
                </select>
                <div v-if="damageForm.errors.product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ damageForm.errors.product_id }}</div>
            </div>
            <div v-if="hasBatchTracking && batchesOf(damageForm.product_id).length" class="field">
                <label>{{ t('damage.batchLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <select v-model="damageForm.product_batch_id">
                    <option :value="''">{{ t('damage.batchAny') }}</option>
                    <option v-for="b in batchesOf(damageForm.product_id)" :key="b.id" :value="b.id">{{ batchLabel(b) }}</option>
                </select>
                <div v-if="damageForm.errors.product_batch_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ damageForm.errors.product_batch_id }}</div>
            </div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('damage.qty') }}</label>
                    <input v-model="damageForm.qty" type="number">
                    <div v-if="damageForm.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ damageForm.errors.qty }}</div>
                </div>
                <div class="field"><label>{{ t('damage.reason') }}</label><input v-model="damageForm.reason" :placeholder="t('damage.reasonPlaceholder')"></div>
            </div>
            <button class="btn rose" :disabled="damageForm.processing" @click="saveDamage">
                {{ damageForm.processing ? '...' : t('damage.submit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="damageSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- return -->
        <Sheet v-model="returnSheet" :title="t('return.sheetTitle')">
            <div class="field">
                <label>{{ t('damage.selectProduct') }}</label>
                <select v-model="returnForm.product_id">
                    <option :value="''">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} — {{ p.price }}৳</option>
                </select>
                <div v-if="returnForm.errors.product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ returnForm.errors.product_id }}</div>
            </div>
            <div v-if="hasBatchTracking && batchesOf(returnForm.product_id).length" class="field">
                <label>{{ t('damage.batchLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <select v-model="returnForm.product_batch_id">
                    <option :value="''">{{ t('damage.batchAny') }}</option>
                    <option v-for="b in batchesOf(returnForm.product_id)" :key="b.id" :value="b.id">{{ batchLabel(b) }}</option>
                </select>
                <div v-if="returnForm.errors.product_batch_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ returnForm.errors.product_batch_id }}</div>
            </div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('return.qty') }}</label>
                    <input v-model="returnForm.qty" type="number">
                    <div v-if="returnForm.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ returnForm.errors.qty }}</div>
                </div>
                <div class="field">
                    <label>{{ t('return.refund') }}</label>
                    <input v-model="returnForm.refund" type="number">
                    <div v-if="returnForm.errors.refund" style="color:var(--rose);font-size:12px;margin-top:6px">{{ returnForm.errors.refund }}</div>
                </div>
            </div>
            <div class="field"><label>{{ t('return.phone') }}</label><input v-model="returnForm.phone"></div>
            <button class="btn" :disabled="returnForm.processing" @click="saveReturn">
                {{ returnForm.processing ? '...' : t('return.submit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="returnSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- supplier return -->
        <Sheet v-model="supplierReturnSheet" :title="t('supplierReturn.sheetTitle')">
            <div v-if="suppliers?.length" class="field">
                <label>{{ t('supplier.title') }}</label>
                <select v-model="supplierReturnForm.supplier_id">
                    <option value="">{{ t('purchase.supplierFreeText') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div v-if="supplierReturnForm.errors.supplier_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ supplierReturnForm.errors.supplier_id }}</div>
            </div>
            <div class="field"><label>{{ t('purchase.supplier') }}</label><input v-model="supplierReturnForm.supplier"></div>
            <div class="field">
                <label>{{ t('damage.selectProduct') }}</label>
                <select v-model="supplierReturnForm.product_id">
                    <option :value="''">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} — {{ t('damage.stockLabel') }}: {{ p.stock }}</option>
                </select>
                <div v-if="supplierReturnForm.errors.product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ supplierReturnForm.errors.product_id }}</div>
            </div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('return.qty') }}</label>
                    <input v-model="supplierReturnForm.qty" type="number">
                    <div v-if="supplierReturnForm.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ supplierReturnForm.errors.qty }}</div>
                </div>
                <div class="field"><label>{{ t('damage.reason') }}</label><input v-model="supplierReturnForm.reason" :placeholder="t('damage.reasonPlaceholder')"></div>
            </div>
            <div class="field">
                <label>{{ t('supplierReturn.settlementLabel') }}</label>
                <div class="seg">
                    <button type="button" :class="{ on: supplierReturnForm.settlement_method === 'payable' }" @click="supplierReturnForm.settlement_method = 'payable'">{{ t('supplierReturn.settlementPayable') }}</button>
                    <button type="button" :class="{ on: supplierReturnForm.settlement_method === 'cash' }" @click="supplierReturnForm.settlement_method = 'cash'">{{ t('pay.cash') }}</button>
                    <button type="button" :class="{ on: supplierReturnForm.settlement_method === 'bank' }" @click="supplierReturnForm.settlement_method = 'bank'">{{ t('purchase.methodBank') }}</button>
                </div>
                <div v-if="supplierReturnForm.errors.settlement_method" style="color:var(--rose);font-size:12px;margin-top:6px">{{ supplierReturnForm.errors.settlement_method }}</div>
            </div>
            <div class="field">
                <label>{{ t('purchase.amount') }}</label>
                <input v-model="supplierReturnForm.amount" type="number">
                <div v-if="supplierReturnForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ supplierReturnForm.errors.amount }}</div>
            </div>
            <button class="btn rose" :disabled="supplierReturnForm.processing" @click="saveSupplierReturn">
                {{ supplierReturnForm.processing ? '...' : t('supplierReturn.submit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="supplierReturnSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- stock count -->
        <Sheet v-model="countSheet" :title="t('count.sheetTitle')" :subtitle="t('count.sheetSub')">
            <div style="max-height:52vh;overflow-y:auto;margin:8px 0">
                <div v-for="p in products" :key="p.id" class="cart-line">
                    <div style="font-size:19px">{{ p.emoji }}</div>
                    <div class="nm"><b>{{ p.name }}</b><span>{{ t('count.systemLabel') }}: {{ p.stock }} {{ t('count.pieces') }}</span></div>
                    <input v-model="counts[p.id]" type="number" :placeholder="String(p.stock)" style="width:70px;padding:8px;text-align:center">
                </div>
            </div>
            <button class="btn" :disabled="countSubmitting" @click="applyCount">
                {{ countSubmitting ? '...' : t('count.submit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="countSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- export -->
        <Sheet v-model="exportSheet" :title="t('export.sheetTitle')">
            <div v-for="[kind, labelKey, em] in [['sales','export.sales','🧾'],['stock','export.stock','📦'],['due','export.due','🧳'],['exp','export.exp','💸'],['damage','export.damage','🗑️'],['return','export.return','↩️'],['pl','export.pl','📊']]" :key="kind" class="row" style="box-shadow:none">
                <div class="ava">{{ em }}</div>
                <div class="mid"><b>{{ t(labelKey) }}</b></div>
                <button class="btn sm" @click="download(kind, 'xlsx')">Excel</button>
                <button class="btn ghost sm" style="margin-left:6px" @click="download(kind, 'csv')">CSV</button>
                <button class="btn ghost sm" style="margin-left:6px" @click="download(kind, 'pdf')">PDF</button>
            </div>
            <button class="btn ghost" style="margin-top:12px" @click="exportSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
