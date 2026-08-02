<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useOfflineSync } from '@/composables/useOfflineSync';

const props = defineProps({
    products: Array,
    categories: Array,
    salesMode: String, // 'scan' | 'manual' | 'both' — set by admin per shop
    heldCarts: { type: Array, default: () => [] },
    prefillQuotation: { type: Object, default: null },
    activeGatewayProviders: { type: Array, default: () => [] },
});

const page = usePage();
const shop = computed(() => page.props.shop);
// complimentary (free/staff-meal) sales give away real inventory for free —
// owner-only, same reasoning as void-sale being owner-only elsewhere
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
const features = computed(() => page.props.features || []);
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const hasUnitConversion = computed(() => features.value.includes('unit_conversion'));
const hasSerialTracking = computed(() => features.value.includes('serial_tracking'));
const hasProductVariants = computed(() => features.value.includes('product_variants'));
const hasPrescriptionRecords = computed(() => features.value.includes('prescription_records'));
const hasLowStockAlerts = computed(() => features.value.includes('low_stock_alerts'));
const hasPromotions = computed(() => features.value.includes('promotions'));
const hasLoyaltyPoints = computed(() => features.value.includes('loyalty_points'));
const hasWholesalePricing = computed(() => features.value.includes('wholesale_pricing'));
const { toast } = useToast();
const { t } = useI18n();
const offlineSync = useOfflineSync();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const canScan = computed(() => props.salesMode === 'scan' || props.salesMode === 'both');

const q = ref('');
const searchInput = ref(null);
const cat = ref('all');
const cart = ref([]); // [{product_id, product_unit_id, qty, discount}]
const cartOpen = ref(false);
const receiptOpen = ref(false);
const lastSale = ref(null);

const payMode = ref('cash');
const saleType = ref('retail'); // 'retail' | 'wholesale' — whole-cart toggle, see PosController::performCheckout
const splitMode = ref(false);
const splitAmounts = ref({ cash: '', bkash: '', nagad: '' });
const discount = ref(0);
const couponCode = ref('');
const redeemPoints = ref('');
const quotationId = ref(null);
const customerPhone = ref('');
const customerName = ref('');
const customerLookup = ref(null); // { found, name, due } — result of the last phone lookup
const submitting = ref(false);
const errorMsg = ref('');
const heldSheet = ref(false);
const prescriptionNote = ref('');
// true the instant a drug-control-flagged product is in the cart — the
// cashier isn't blocked from checking out (no digital way to verify a real
// prescription), just reminded and given a place to note what was checked
const cartNeedsPrescription = computed(() => hasPrescriptionRecords.value && cart.value.some((l) => productOf(l.product_id)?.requires_prescription));

const filtered = computed(() => props.products.filter((p) =>
    (!q.value
        || p.name.toLowerCase().includes(q.value.toLowerCase())
        || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
        || (p.barcode || '').includes(q.value)
        // generic-name search: if the exact brand a customer asks for is
        // out of stock, typing the active ingredient (e.g. "Paracetamol")
        // still surfaces every other brand that shares it
        || (p.generic_name || '').toLowerCase().includes(q.value.toLowerCase())
    ) &&
    (cat.value === 'all' || p.category_id === cat.value)
));

function qtyInCart(productId) {
    return cart.value.filter((l) => l.product_id === productId).reduce((s, l) => s + l.qty, 0);
}

function unitOf(product, productUnitId) {
    if (!productUnitId) return { label: product.unit?.name || '', price: product.price, factor: 1 };
    const pu = product.product_units?.find((u) => u.id === productUnitId);
    return pu ? { label: pu.unit?.name, price: pu.price, factor: pu.factor } : { label: '', price: product.price, factor: 1 };
}

function variantOf(product, productVariantId) {
    return productVariantId ? product.variants?.find((v) => v.id === productVariantId) : null;
}
function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}

/** A cart line is either a base sale, a pack-unit line, or a variant line — never a mix, mirrors PosController::checkout. */
function addToCart(productId, productUnitId = null, { silent, productVariantId = null } = {}) {
    if (!productId) return;
    const product = props.products.find((p) => p.id === productId);
    if (!product) return;

    if (productVariantId) {
        const variant = variantOf(product, productVariantId);
        if (!variant) return;
        const alreadyInCart = cart.value.reduce((s, l) => s + (l.product_variant_id === productVariantId ? l.qty : 0), 0);
        if (variant.stock <= alreadyInCart) {
            toast('❌ ' + t('pos.notEnoughStock') + ' ' + product.name + ' (' + variantLabel(variant) + ')');
            return;
        }

        const line = cart.value.find((l) => l.product_variant_id === productVariantId);
        if (line) line.qty++;
        else cart.value.push({ product_id: productId, product_unit_id: null, product_variant_id: productVariantId, qty: 1, discount: 0, imei: '' });

        if (!silent) toast('✅ ' + product.name + ' (' + variantLabel(variant) + ') ' + t('pos.added'));
        return;
    }

    const { label, factor } = unitOf(product, productUnitId);
    const alreadyInCartBase = cart.value.reduce((s, l) => s + (!l.product_variant_id && l.product_id === productId ? l.qty * unitOf(product, l.product_unit_id).factor : 0), 0);
    if (product.stock <= alreadyInCartBase + factor - 1) {
        toast('❌ ' + t('pos.notEnoughStock') + ' ' + product.name);
        return;
    }

    const line = cart.value.find((l) => l.product_id === productId && l.product_unit_id === productUnitId && !l.product_variant_id);
    if (line) line.qty++;
    else cart.value.push({ product_id: productId, product_unit_id: productUnitId, product_variant_id: null, qty: 1, discount: 0, imei: '' });

    if (!silent) toast('✅ ' + product.name + (label ? ` (${label})` : '') + ' ' + t('pos.added'));
}

function inc(line, d) {
    line.qty += d;
    if (line.qty <= 0) cart.value = cart.value.filter((l) => l !== line);
}

// --- weight/volume quick-entry — a loose product's base-unit line is a
// decimal kg/litre qty, so a plain ±1 stepper makes no sense; tapping the
// card (or its qty) instead opens this sheet to type/pick an exact weight ---
function isWeighedLine(l) {
    return !l.product_variant_id && !l.product_unit_id && !!productOf(l.product_id)?.sold_by_weight;
}
function weightSmallUnit(p) {
    return p.weight_unit === 'litre' ? 'ml' : 'g';
}
function weightBigUnit(p) {
    return p.weight_unit === 'litre' ? t('stock.unitLitre') : t('stock.unitKg');
}
/** e.g. 0.25 kg → "250 g", 1.5 kg → "1.5 kg" — grams for anything under 1 base unit, otherwise the base unit, never both */
function formatWeightQty(p, qty) {
    const n = Number(qty);
    if (n < 1) return `${Math.round(n * 1000)} ${weightSmallUnit(p)}`;
    return `${Math.round(n * 1000) / 1000} ${weightBigUnit(p)}`;
}

// low-stock warning between "plenty" and "out" — a cashier restocking
// decisions benefit from seeing this before it actually runs out, not just
// a bare "out of stock" after the fact. Uses the product's own owner-set
// reorder_point when configured, same threshold Product::isLow() uses
// server-side otherwise (kept in sync deliberately, not re-derived oddly).
function stockLevel(p) {
    const stock = Number(p.stock);
    if (stock <= 0) return 'out';
    const threshold = Number(p.reorder_point) > 0 ? Number(p.reorder_point) : 6;
    return stock <= threshold ? 'low' : 'ok';
}

const weightSheet = ref(false);
const weightProduct = ref(null);
const weightEntryUnit = ref('g'); // 'g'/'kg' or 'ml'/'litre', matches the product's weight_unit
const weightValue = ref('');
const WEIGHT_PRESETS_G = [100, 250, 500, 1000, 2000];

function openWeightEntry(p) {
    if (p.stock <= 0) return;
    weightProduct.value = p;
    weightEntryUnit.value = weightSmallUnit(p);
    const existing = cart.value.find((l) => l.product_id === p.id && !l.product_variant_id && !l.product_unit_id);
    weightValue.value = existing ? String(Math.round(Number(existing.qty) * 1000)) : '';
    weightSheet.value = true;
}
function pickWeightPreset(grams) {
    weightEntryUnit.value = weightSmallUnit(weightProduct.value);
    weightValue.value = String(grams);
}
function toggleWeightEntryUnit() {
    const p = weightProduct.value;
    const n = Number(weightValue.value) || 0;
    const isSmall = weightEntryUnit.value === weightSmallUnit(p);
    weightEntryUnit.value = isSmall ? p.weight_unit : weightSmallUnit(p);
    weightValue.value = n ? String(isSmall ? n / 1000 : Math.round(n * 1000)) : '';
}
function confirmWeightEntry() {
    const p = weightProduct.value;
    const raw = Number(weightValue.value);
    if (!raw || raw <= 0) return;
    const qty = weightEntryUnit.value === weightSmallUnit(p) ? raw / 1000 : raw;

    if (Number(p.stock) < qty) {
        toast('❌ ' + t('pos.notEnoughStock') + ' ' + p.name + ` (${t('stock.currentStock')} ${formatWeightQty(p, p.stock)})`);
        return;
    }

    const line = cart.value.find((l) => l.product_id === p.id && !l.product_variant_id && !l.product_unit_id);
    if (line) line.qty = qty;
    else cart.value.push({ product_id: p.id, product_unit_id: null, product_variant_id: null, qty, discount: 0, imei: '' });

    weightSheet.value = false;
    toast('✅ ' + p.name + ' ' + t('pos.added'));
}
function removeWeightLine() {
    const p = weightProduct.value;
    cart.value = cart.value.filter((l) => !(l.product_id === p.id && !l.product_variant_id && !l.product_unit_id));
    weightSheet.value = false;
}

const cartCount = computed(() => cart.value.reduce((s, l) => s + l.qty, 0));

function productOf(id) {
    return props.products.find((p) => p.id === id);
}

/** raw line total before this line's own discount is taken off — mirrors PosController::performCheckout's own price resolution exactly, including the wholesale-price-on-the-base-line-only rule, so what the cashier sees here always matches what the server actually charges */
function lineRaw(l) {
    const p = productOf(l.product_id);
    if (!p) return 0;
    if (l.product_variant_id) {
        const v = variantOf(p, l.product_variant_id);
        return (v ? Number(v.price ?? p.price) : p.price) * l.qty;
    }
    if (!l.product_unit_id && saleType.value === 'wholesale' && p.wholesale_price != null) {
        return Number(p.wholesale_price) * l.qty;
    }
    return unitOf(p, l.product_unit_id).price * l.qty;
}

/** what this line actually contributes to the bill, after its own discount */
function lineTotal(l) {
    return Math.max(0, lineRaw(l) - (l.discount || 0));
}

const subtotal = computed(() => cart.value.reduce((s, l) => s + lineTotal(l), 0));
// mirrors the server's own PosController::checkout() math exactly — service
// charge is additive on top of the discounted subtotal, unlike VAT (which is
// backed out of an already-inclusive price and shown separately, never added)
const serviceCharge = computed(() => {
    const rate = shop.value?.service_charge_rate;
    if (rate === null || rate === undefined) return 0;
    return Math.round(Math.max(0, subtotal.value - (discount.value || 0)) * Number(rate) / 100 * 100) / 100;
});
const total = computed(() => Math.max(0, subtotal.value - (discount.value || 0)) + serviceCharge.value);

// Split mode: cashier types how much came in via each method; whatever's
// left of the total is shown live as due (server enforces the same math —
// this is just so the cashier sees it before tapping checkout).
const splitTendered = computed(() => ['cash', 'bkash', 'nagad'].reduce((s, m) => s + (Number(splitAmounts.value[m]) || 0), 0));
const splitRemainder = computed(() => Math.max(0, Math.round((total.value - splitTendered.value) * 100) / 100));

function buildPayments() {
    if (splitMode.value) {
        return ['cash', 'bkash', 'nagad']
            .filter((m) => Number(splitAmounts.value[m]) > 0)
            .map((m) => ({ method: m, amount: Number(splitAmounts.value[m]) }));
    }
    if (payMode.value === 'credit' || payMode.value === 'complimentary') return [];
    return [{ method: payMode.value, amount: total.value }];
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function buildCheckoutPayload() {
    return {
        items: cart.value.map((l) => ({ product_id: l.product_id, product_unit_id: l.product_unit_id, product_variant_id: l.product_variant_id, qty: l.qty, discount: l.discount || 0, imei: l.imei || '' })),
        discount: discount.value || 0,
        complimentary: payMode.value === 'complimentary',
        coupon_code: couponCode.value || '',
        redeem_points: redeemPoints.value || 0,
        quotation_id: quotationId.value,
        payments: buildPayments(),
        customer_phone: customerPhone.value,
        customer_name: customerName.value,
        prescription_note: cartNeedsPrescription.value ? prescriptionNote.value : '',
        sale_type: saleType.value,
    };
}

function resetCartAfterCheckout() {
    cart.value = [];
    discount.value = 0;
    couponCode.value = '';
    redeemPoints.value = '';
    quotationId.value = null;
    customerPhone.value = '';
    customerName.value = '';
    customerLookup.value = null;
    splitMode.value = false;
    splitAmounts.value = { cash: '', bkash: '', nagad: '' };
    saleType.value = 'retail';
    prescriptionNote.value = '';
    cartOpen.value = false;
}

// The checkout endpoint returns JSON (not an Inertia response), so use fetch
// directly to get the sale/receipt payload back without a full page visit.
async function submitCheckout() {
    if (!cart.value.length || submitting.value) return;
    submitting.value = true;
    errorMsg.value = '';
    const payload = buildCheckoutPayload();

    try {
        const res = await fetch(route('app.pos.checkout'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json();

        if (!res.ok) {
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || t('pos.checkoutError');
            return;
        }

        lastSale.value = data.sale;
        resetCartAfterCheckout();
        receiptOpen.value = true;
        router.reload({ only: ['products'] });
    } catch (e) {
        // A real connectivity failure (fetch itself throwing, or
        // navigator.onLine already false) never loses the sale — it's
        // queued locally and replayed automatically once the connection
        // returns (see useOfflineSync), same as a cashier's paper backup
        // notebook, but self-syncing instead of needing manual re-entry.
        if (!navigator.onLine || e instanceof TypeError) {
            await offlineSync.queueSale(payload);
            resetCartAfterCheckout();
            toast(t('pos.savedOffline'));
        } else {
            errorMsg.value = t('pos.networkError');
        }
    } finally {
        submitting.value = false;
    }
}

// --- pay via gateway (bring-your-own-key bKash/Nagad/SSLCommerz) — only
// shown when the shop has at least one active connection; falls back to
// the manual cash/bkash/nagad/credit buttons above otherwise ---
const gatewaySubmitting = ref(false);
async function payViaGateway(provider) {
    if (!cart.value.length || gatewaySubmitting.value) return;
    gatewaySubmitting.value = true;
    errorMsg.value = '';

    try {
        const res = await fetch(route('app.gatewayCheckout.initiate', provider), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify({
                items: cart.value.map((l) => ({ product_id: l.product_id, product_unit_id: l.product_unit_id, product_variant_id: l.product_variant_id, qty: l.qty, discount: l.discount || 0, imei: l.imei || '' })),
                discount: discount.value || 0,
                coupon_code: couponCode.value || '',
                redeem_points: redeemPoints.value || 0,
                quotation_id: quotationId.value,
                customer_phone: customerPhone.value,
                customer_name: customerName.value,
                prescription_note: cartNeedsPrescription.value ? prescriptionNote.value : '',
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.redirect_url) {
            errorMsg.value = data.message || t('pos.checkoutError');
            return;
        }
        window.location.href = data.redirect_url; // leaves the app — the customer completes payment on the gateway's own page
    } catch (e) {
        errorMsg.value = t('pos.networkError');
    } finally {
        gatewaySubmitting.value = false;
    }
}

// after the gateway redirects the browser back (GatewayWebhookController::callback),
// the URL carries ?gateway_reference=...&gateway_status=... — poll our own
// status endpoint until the async IPN/webhook has actually finalized the
// sale, since the browser redirect and that confirmation don't always land
// in a guaranteed order
const gatewayPolling = ref(false);
async function pollGatewayStatus(reference) {
    gatewayPolling.value = true;
    for (let attempt = 0; attempt < 15; attempt++) {
        try {
            const res = await fetch(route('app.gatewayCheckout.status', reference), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (data.status === 'completed') {
                toast('✅ ' + t('pos.billComplete'));
                router.reload({ only: ['products'] });
                gatewayPolling.value = false;
                return;
            }
            if (data.status === 'failed') {
                toast('❌ ' + t('pos.checkoutError'));
                gatewayPolling.value = false;
                return;
            }
        } catch (e) { /* keep retrying */ }
        await new Promise((resolve) => setTimeout(resolve, 2000));
    }
    gatewayPolling.value = false;
}
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const reference = params.get('gateway_reference');
    if (reference) {
        pollGatewayStatus(reference);
        window.history.replaceState({}, '', window.location.pathname); // drop the query params so a refresh doesn't re-poll
    }
});

/** Parks the current cart server-side (e.g. this customer stepped away to grab something else) and clears the screen for the next one. */
function holdCart() {
    if (!cart.value.length) return;
    router.post(route('app.heldCarts.store'), {
        cart: cart.value,
        discount: discount.value || 0,
        customer_phone: customerPhone.value,
        customer_name: customerName.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            cart.value = [];
            discount.value = 0;
            customerPhone.value = '';
            customerName.value = '';
            customerLookup.value = null;
            cartOpen.value = false;
        },
    });
}

/** Loads a previously-held cart back into the active one — a plain fetch (not an Inertia visit) since it needs the JSON payload back, mirrors submitCheckout's own pattern. */
async function resumeHeldCart(id) {
    const res = await fetch(route('app.heldCarts.resume', id), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
    });
    if (!res.ok) return;
    const data = await res.json();

    cart.value = data.cart || [];
    discount.value = data.discount || 0;
    customerPhone.value = data.customer_phone || '';
    customerName.value = data.customer_name || '';
    heldSheet.value = false;
    cartOpen.value = true;
    router.reload({ only: ['heldCarts'] });
}

function deleteHeldCart(id) {
    if (!confirm(t('pos.deleteHeldConfirm'))) return;
    router.delete(route('app.heldCarts.destroy', id), { preserveScroll: true });
}

// Vue's template compiler only resolves a fixed allowlist of globals
// (Math, Date, JSON, ...) inline in `@click="..."` expressions — `window`
// isn't on that list, so `@click="window.print()"` compiles to
// `_ctx.window.print()`, and since the component has no `window` property
// that throws "Cannot read properties of undefined" silently (Vue logs it
// to the console but the click otherwise does nothing visible) instead of
// ever opening the print dialog. A real method sidesteps this entirely —
// `window` inside plain script code is just the normal global, no compiler
// rewriting involved.
function printMemo() {
    window.print();
}

// same CODE128 (scans back to this exact sale) + rating QR as the reprint
// page (Sales/Show.vue) — re-rendered every time a new sale completes, since
// this sheet's #printable-memo is reused across sales, not remounted fresh
watch(lastSale, async (sale) => {
    if (!sale) return;
    await nextTick();
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById('pos-receipt-barcode-svg');
    if (el) {
        try {
            JsBarcode(el, 'INV-' + sale.id, { format: 'CODE128', width: 1.3, height: 30, fontSize: 9, margin: 2 });
        } catch (e) { /* shouldn't happen — id is always numeric */ }
    }
    const QRCode = (await import('qrcode')).default;
    const qrEl = document.getElementById('pos-receipt-rating-qr');
    if (qrEl) {
        try {
            await QRCode.toCanvas(qrEl, window.location.origin + route('rate.show', sale.id), { width: 90, margin: 1 });
        } catch (e) { /* non-critical */ }
    }
}, { flush: 'post' });

function sendMemoWA() {
    const s = lastSale.value;
    if (!s) return;
    const phone = s.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    const lines = s.items.map((l) => `${l.product_name}  ${l.qty}×${l.price} = ${money(l.price * l.qty)}`).join('\n');
    const shopName = shop.value?.name || 'Zaylotix POS';
    const text = `*${shopName}*\n${'─'.repeat(16)}\nমেমো: ${s.invoice_no}\n${'─'.repeat(16)}\n${lines}\n${'─'.repeat(16)}\n*মোট: ${money(s.total)}*\n\nধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
}

// as soon as a full-looking phone number is typed, check if this customer
// already exists so their name (and outstanding due) shows automatically —
// saves re-typing the name for a repeat customer and avoids duplicate records
let lookupTimer = null;
watch(customerPhone, (val) => {
    clearTimeout(lookupTimer);
    customerLookup.value = null;
    redeemPoints.value = '';
    const phone = (val || '').trim();
    if (phone.length < 6) return;

    lookupTimer = setTimeout(async () => {
        try {
            const res = await fetch(route('app.customers.lookup') + '?phone=' + encodeURIComponent(phone), {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            customerLookup.value = data;
            if (data.found && !customerName.value.trim()) {
                customerName.value = data.name;
            }
        } catch (e) { /* not critical to checkout — silently skip */ }
    }, 400);
});

/* ---------------- barcode scanner ---------------- */
const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
let lastScanCode = '';
let lastScanAt = 0;

async function openScanner() {
    if (!canScan.value) {
        toast('❌ ' + t('pos.scannerDisabled'));
        return;
    }
    scannerOpen.value = true;
    scanHint.value = t('pos.scanHintDefault');

    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            onScanSuccess,
            () => {},
        );
    } catch (e) {
        toast('❌ ' + t('pos.cameraNotFound'));
        closeScanner();
    }
}

async function closeScanner() {
    scannerOpen.value = false;
    if (html5Qrcode) {
        try {
            await html5Qrcode.stop();
            html5Qrcode.clear();
        } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}

/**
 * Shared by the camera scanner and the hardware (keyboard-wedge) scanner —
 * both ultimately just produce a decoded barcode string, the lookup/add-to-
 * cart logic is identical either way. `onFeedback` lets each caller show
 * the result its own way (the camera overlay's scanHint text vs a toast for
 * a hardware scan, which has no overlay to show a hint in).
 */
async function handleBarcode(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return; // debounce repeat frames
    lastScanCode = decodedText;
    lastScanAt = now;

    if (navigator.vibrate) navigator.vibrate(40);

    // a receipt's own barcode (printed on every memo) encodes "INV-{sale id}"
    // — distinct from a product barcode, so it can be told apart before ever
    // reaching the product lookup below; scanning an old receipt just reopens it
    const invMatch = decodedText.trim().match(/^INV-(\d+)$/);
    if (invMatch) {
        closeScanner();
        router.visit(route('app.sales.show', invMatch[1]));
        return;
    }

    const product = props.products.find((p) => p.barcode === decodedText.trim());
    if (product) {
        // a weighed product's barcode can't carry a fixed qty — always
        // route it through the weight-entry sheet instead of assuming 1 unit
        if (product.sold_by_weight) { closeScanner(); openWeightEntry(product); return; }
        addToCart(product.id);
        onFeedback('✅ ' + product.name);
    } else {
        // not in the locally loaded list (e.g. added by another device since page load) — ask the server
        try {
            const res = await fetch(route('app.pos.barcode', decodedText.trim()), {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.found) {
                props.products.push(data.product);
                if (data.product.sold_by_weight) { closeScanner(); openWeightEntry(data.product); return; }
                addToCart(data.product.id);
                onFeedback('✅ ' + data.product.name);
            } else {
                onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
            }
        } catch (e) {
            onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
        }
    }
}

function onScanSuccess(decodedText) {
    handleBarcode(decodedText, (msg) => (scanHint.value = msg));
}

// --- hardware (USB/Bluetooth keyboard-wedge) scanner ---
const hardwareScannerEnabled = computed(() => shop.value?.hardware_scanner_enabled ?? true);
useHardwareScanner((code) => handleBarcode(code, (msg) => toast(msg)), hardwareScannerEnabled);

onBeforeUnmount(() => {
    if (html5Qrcode) {
        html5Qrcode.stop().catch(() => {});
    }
});

// This is a normal request/response app, not real-time — if a cashier on
// another phone sells the last piece of something, this screen won't know
// until it asks again. Quietly re-check stock every 15s (skipped while the
// cart/scanner is open so it can never yank the sheet out from under
// someone mid-checkout) so two people ringing up the same shop don't both
// oversell the same item between page loads.
// Converting an open quotation into a real sale — prefill the cart from
// its snapshot instead of duplicating any stock/money logic here; the
// actual conversion only ever happens through the normal checkout() call
// below (see PosController::checkout()'s `quotation_id` handling), so this
// is purely a convenience fill of the same cart a cashier would build by
// hand. If the live price has since gone UP, a compensating line discount
// keeps the customer paying no more than what was quoted — if it's gone
// down, they simply benefit from the lower live price.
onMounted(() => {
    if (props.prefillQuotation) {
        const q = props.prefillQuotation;
        cart.value = q.items.map((l) => {
            const live = props.products.find((p) => p.id === l.product_id);
            const livePrice = live ? Number(live.price) : Number(l.price);
            const quotedUnit = (Number(l.price) * l.qty - Number(l.discount || 0)) / l.qty;
            const compDiscount = Math.max(0, Math.round((livePrice - quotedUnit) * l.qty * 100) / 100);
            return { product_id: l.product_id, product_unit_id: null, product_variant_id: null, qty: l.qty, discount: compDiscount, imei: '' };
        });
        quotationId.value = q.id;
        customerPhone.value = q.customer_phone || '';
        customerName.value = q.customer_name || '';
        cartOpen.value = true;
        toast('📋 ' + t('quotation.prefilledToast', { no: q.quote_no }));
    }
});

let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (cartOpen.value || scannerOpen.value || receiptOpen.value || submitting.value) return;
        router.reload({ only: ['products'], preserveScroll: true, preserveState: true });
    }, 15000);
});
onBeforeUnmount(() => clearInterval(pollTimer));

// F-keys never collide with normal typing, so these work regardless of
// which field currently has focus — F2 to jump into product search, F9 to
// open checkout once something's in the cart, F4 to hold the cart for
// later, Escape to back out of whatever's open right now.
useKeyboardShortcuts({
    F2: () => searchInput.value?.focus(),
    F9: () => { if (cart.value.length) cartOpen.value = true; },
    F4: () => holdCart(),
    Escape: () => {
        if (scannerOpen.value) closeScanner();
        else if (cartOpen.value) cartOpen.value = false;
    },
});
</script>

<template>
    <Head :title="t('nav.sell')" />
    <AppLayout active="sell">
        <div class="pgttl">{{ t('pos.title') }}</div>
        <div class="pgsub">{{ t('pos.subtitleTap') }}{{ canScan ? t('pos.subtitleScan') : '' }}</div>
        <HowToHint screen-key="pos" />
        <div class="hidden lg:block" style="font-size:11.5px;color:var(--dim);margin-bottom:10px">{{ t('pos.shortcutsHint') }}</div>

        <div class="lg:flex lg:gap-6 lg:items-start">
            <!-- category rail — desktop only, the mobile tabbar below covers the same job on phone -->
            <aside class="hidden lg:block lg:order-1 lg:w-52 lg:shrink-0 lg:sticky lg:top-6">
                <button class="rest-rail-btn" :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                <button v-for="c in categories" :key="c.id" class="rest-rail-btn" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
            </aside>

            <!-- menu -->
            <div class="lg:order-2 lg:flex-1 lg:min-w-0">
                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <input ref="searchInput" v-model="q" :placeholder="t('pos.searchPlaceholder')" style="flex:1">
                    <button v-if="canScan" class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openScanner">📷</button>
                    <button class="btn ghost lg:hidden" style="width:auto;padding:0 16px;position:relative" :title="t('pos.heldCarts')" @click="heldSheet = true">
                        📋
                        <span v-if="heldCarts.length" style="position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:var(--rose);color:#fff;font-size:9.5px;font-weight:800;display:flex;align-items:center;justify-content:center">{{ heldCarts.length }}</span>
                    </button>
                </div>

                <div class="tabbar lg:hidden">
                    <button :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                    <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
                </div>

                <div v-if="filtered.length" class="pgrid rest-grid">
                    <div v-for="p in filtered" :key="p.id" class="pcard" :class="{ incart: qtyInCart(p.id) }">
                        <span v-if="p.sold_by_weight && qtyInCart(p.id)" class="qbadge">{{ formatWeightQty(p, qtyInCart(p.id)) }}</span>
                        <span v-else-if="qtyInCart(p.id)" class="qbadge">{{ qtyInCart(p.id) }}</span>
                        <!-- a weighed product always opens the weight-entry sheet — a plain tap-to-add-1 makes no sense for a loose kg/litre item -->
                        <button v-if="p.sold_by_weight" class="pcard-main" @click="openWeightEntry(p)">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div class="pp">{{ money(p.price) }} <span style="color:var(--dim);font-weight:500">/ {{ weightBigUnit(p) }}</span></div>
                            <div class="ps">
                                <span v-if="stockLevel(p) === 'out'" style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                                <span v-else-if="hasLowStockAlerts && stockLevel(p) === 'low'" style="color:var(--gold2);font-weight:700">⚠ {{ formatWeightQty(p, p.stock) }} {{ t('pos.lowStock') }}</span>
                                <span v-else>{{ formatWeightQty(p, p.stock) }} {{ t('stock.stock').toLowerCase() }}</span>
                            </div>
                        </button>
                        <!-- a product with variants can't be sold "in general" — the size/color pills below are the only way to add it -->
                        <button v-else-if="!(hasProductVariants && p.variants?.length)" class="pcard-main" @click="addToCart(p.id, null)">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }} <span v-if="p.requires_prescription" style="color:var(--rose)">℞</span></div>
                            <div v-if="p.generic_name" class="pgeneric">{{ p.generic_name }}</div>
                            <div class="pp">{{ money(p.price) }} <span style="color:var(--dim);font-weight:500">/ {{ p.unit?.name || t('pos.unit') }}</span></div>
                            <div class="ps">
                                <span v-if="stockLevel(p) === 'out'" style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                                <span v-else-if="hasLowStockAlerts && stockLevel(p) === 'low'" style="color:var(--gold2);font-weight:700">⚠ {{ t('pos.inStock', { n: p.stock }) }} {{ t('pos.lowStock') }}</span>
                                <span v-else>{{ t('pos.inStock', { n: p.stock }) }}</span>
                            </div>
                        </button>
                        <div v-else class="pcard-main" style="cursor:default">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div class="pp">{{ money(p.price) }}</div>
                            <div class="ps">{{ t('pos.pickVariant') }}</div>
                        </div>
                        <!-- pack-size options (box/strip/...) shown directly here — tap to add that exact unit, no extra popup step -->
                        <div v-if="hasUnitConversion && p.product_units?.length" class="punits">
                            <button v-for="pu in p.product_units" :key="pu.id" class="upill" @click="addToCart(p.id, pu.id)">
                                {{ pu.unit?.name }} {{ money(pu.price) }}
                            </button>
                        </div>
                        <!-- size/color variant options — same pill treatment as pack sizes, each carries its own stock -->
                        <div v-if="hasProductVariants && p.variants?.length" class="punits">
                            <!-- a variant's own price only shows when it actually
                                 differs from the base product's — e.g. an XXL that
                                 costs more — so the common same-price-every-size
                                 case doesn't get cluttered with a redundant number -->
                            <button
                                v-for="v in p.variants" :key="v.id" class="upill"
                                :disabled="v.stock <= 0"
                                :style="v.stock <= 0 ? 'opacity:.45' : ''"
                                @click="addToCart(p.id, null, { productVariantId: v.id })"
                            >
                                {{ variantLabel(v) }}<span v-if="Number(v.price) !== Number(p.price)"> {{ money(v.price) }}</span> {{ v.stock > 0 ? `(${v.stock})` : `— ${t('pos.outOfStock')}` }}
                            </button>
                        </div>
                    </div>
                </div>
                <div v-else class="empty"><div class="big">🔍</div>{{ t('pos.notFound') }}</div>
            </div>

            <!-- order panel — desktop only, a live read view of the cart with the full editable Sheet one tap away for payment/discount/customer details -->
            <aside class="hidden lg:block lg:order-3 lg:w-80 lg:shrink-0 lg:sticky lg:top-6">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <b style="font-size:15px">{{ t('pos.cartTitle') }}</b>
                    <button class="btn sm ghost" style="width:auto;padding:6px 12px;position:relative" @click="heldSheet = true">
                        📋 {{ t('pos.heldCarts') }}
                        <span v-if="heldCarts.length" style="position:absolute;top:-6px;right:-6px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:var(--rose);color:#fff;font-size:9.5px;font-weight:800;display:flex;align-items:center;justify-content:center">{{ heldCarts.length }}</span>
                    </button>
                </div>
                <div class="card" style="padding:0;margin-bottom:14px">
                    <template v-if="cart.length">
                        <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px;padding:8px 12px;font-size:10.5px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid var(--line)">
                            <span>{{ t('pos.item') }}</span><span>{{ t('pos.qty') }}</span><span style="text-align:right">{{ t('pos.lineTotal') }}</span>
                        </div>
                        <div v-for="l in cart" :key="l.product_id + ':' + (l.product_unit_id || l.product_variant_id || 'base')" style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;padding:9px 12px;border-bottom:1px solid var(--line);font-size:12.5px">
                            <span style="min-width:0"><b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ productOf(l.product_id)?.name }}</b></span>
                            <span v-if="isWeighedLine(l)" style="display:flex;align-items:center;gap:4px">
                                <button class="qbtn sm ghost" style="width:auto;padding:0 8px;font-size:11px" @click="openWeightEntry(productOf(l.product_id))">{{ formatWeightQty(productOf(l.product_id), l.qty) }} ✎</button>
                            </span>
                            <span v-else style="display:flex;align-items:center;gap:4px">
                                <button class="qbtn" style="width:22px;height:22px;font-size:13px" @click="inc(l, -1)">−</button>
                                <span class="qn" style="min-width:16px;font-size:12px">{{ l.qty }}</span>
                                <button class="qbtn" style="width:22px;height:22px;font-size:13px" @click="inc(l, 1)">＋</button>
                            </span>
                            <b style="text-align:right">{{ money(lineTotal(l)) }}</b>
                        </div>
                    </template>
                    <div v-else class="empty" style="padding:24px 0"><div class="big">🛒</div>{{ t('pos.cartEmpty') }}</div>
                </div>
                <div v-if="cart.length" class="card" style="margin-bottom:14px">
                    <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800"><span>{{ t('pos.subtotal') }}</span><b style="color:var(--gold)">{{ money(subtotal) }}</b></div>
                </div>
                <button class="btn" :disabled="!cart.length" @click="cartOpen = true">{{ t('pos.billButton') }}</button>
            </aside>
        </div>

        <div style="height:78px" class="lg:hidden"></div>
        <div class="posbar lg:hidden">
            <div v-if="!cartCount" class="posbar-empty">{{ t('pos.cartEmptyHint') }}{{ canScan ? t('pos.orScan') : '' }}</div>
            <button v-else class="btn" style="box-shadow:0 6px 18px rgba(242,106,27,.4)" @click="cartOpen = true">
                <span style="display:flex;align-items:center;gap:8px"><span class="cartcount">{{ cartCount }}</span> {{ t('pos.billButton') }}</span>
                <span style="margin-left:auto;font-weight:850">{{ money(subtotal) }}</span>
            </button>
        </div>

        <!-- barcode scanner overlay -->
        <Teleport to="body">
            <div v-if="scannerOpen" id="scanwrap">
                <div class="scan-top">
                    <button class="scan-x" @click="closeScanner">✕</button>
                    <div style="color:#fff;font-weight:700">{{ t('pos.scanProductTitle') }}</div>
                </div>
                <div id="reader"></div>
                <div class="scan-frame"></div>
                <div class="scan-hint">{{ scanHint }}</div>
                <div class="scan-manual">
                    <button class="btn ghost" style="background:rgba(0,0,0,.55);color:#fff;border-color:rgba(255,255,255,.25)" @click="closeScanner">
                        {{ t('pos.addFromList') }}
                    </button>
                </div>
            </div>
        </Teleport>

        <!-- cart / checkout sheet -->
        <Sheet v-model="cartOpen">
            <div class="shttl">{{ t('pos.cartTitle') }} <span class="pill gold" style="vertical-align:middle">{{ t('pos.cartItemsCount', { n: cartCount }) }}</span></div>

            <div v-if="cart.length">
                <div style="margin:6px 0 8px">
                    <div v-for="l in cart" :key="l.product_id + ':' + (l.product_unit_id || l.product_variant_id || 'base')" style="border-bottom:1px solid var(--line);padding:11px 0">
                        <div class="cart-line" style="border:none;padding:0">
                            <div style="font-size:20px">{{ productOf(l.product_id)?.emoji }}</div>
                            <div class="nm">
                                <b>
                                    {{ productOf(l.product_id)?.name }}
                                    <span v-if="l.product_variant_id && variantOf(productOf(l.product_id), l.product_variant_id)" style="color:var(--mut);font-weight:500"> ({{ variantLabel(variantOf(productOf(l.product_id), l.product_variant_id)) }})</span>
                                    <span v-else-if="unitOf(productOf(l.product_id), l.product_unit_id).label" style="color:var(--mut);font-weight:500"> ({{ unitOf(productOf(l.product_id), l.product_unit_id).label }})</span>
                                </b>
                                <span>
                                    {{ money(lineRaw(l) / l.qty) }} × {{ isWeighedLine(l) ? formatWeightQty(productOf(l.product_id), l.qty) : l.qty }} = {{ money(lineTotal(l)) }}
                                    <span v-if="l.discount > 0" style="color:var(--rose)"> (−{{ money(l.discount) }} {{ t('pos.itemDiscountApplied') }})</span>
                                </span>
                            </div>
                            <template v-if="isWeighedLine(l)">
                                <button class="btn sm ghost" style="width:auto;padding:6px 12px" @click="openWeightEntry(productOf(l.product_id))">✎ {{ t('common.edit') }}</button>
                            </template>
                            <template v-else>
                                <button class="qbtn" @click="inc(l, -1)">−</button>
                                <span class="qn">{{ l.qty }}</span>
                                <button class="qbtn" @click="inc(l, 1)">＋</button>
                            </template>
                        </div>
                        <div class="cart-line-discount">
                            <span>{{ t('pos.itemDiscount') }}</span>
                            <input v-model.number="l.discount" type="number" inputmode="numeric" placeholder="0" min="0">
                        </div>
                        <div v-if="hasSerialTracking && !l.product_variant_id && !l.product_unit_id" class="cart-line-discount">
                            <span>{{ t('pos.imei') }}</span>
                            <input v-model="l.imei" :placeholder="t('pos.imeiOptional')">
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label>{{ t('pos.mobileLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.mobileHint') }}</span></label>
                    <input v-model="customerPhone" inputmode="tel" placeholder="01XXXXXXXXX">
                    <div v-if="customerLookup?.found" style="font-size:12px;color:var(--green);margin-top:5px;font-weight:600">
                        ✅ {{ t('pos.oldCustomer') }} {{ customerLookup.name }}<span v-if="customerLookup.due > 0"> {{ t('pos.dueExists', { due: money(customerLookup.due) }) }}</span>
                    </div>
                </div>

                <div v-if="hasLoyaltyPoints && customerLookup?.found && customerLookup.loyalty_points > 0" class="field">
                    <label>{{ t('pos.redeemPoints') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.redeemPointsHint', { points: customerLookup.loyalty_points }) }}</span></label>
                    <input v-model.number="redeemPoints" type="number" inputmode="numeric" placeholder="0" min="0" :max="customerLookup.loyalty_points">
                </div>
                <div class="field">
                    <label>{{ t('pos.customerName') }}</label>
                    <input v-model="customerName" :placeholder="t('pos.customerName')">
                </div>

                <div v-if="hasWholesalePricing" class="field">
                    <label>{{ t('pos.saleTypeLabel') }}</label>
                    <div class="seg">
                        <button :class="{ on: saleType === 'retail' }" @click="saleType = 'retail'">{{ t('pos.retail') }}</button>
                        <button :class="{ on: saleType === 'wholesale' }" @click="saleType = 'wholesale'">{{ t('pos.wholesale') }}</button>
                    </div>
                </div>

                <div class="field">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <label style="margin-bottom:0">{{ t('pos.payment') }}</label>
                        <button type="button" style="border:none;background:none;color:var(--sky);font-size:12px;font-weight:700;padding:4px 0" @click="splitMode = !splitMode">
                            {{ splitMode ? t('pos.splitOff') : t('pos.splitPayment') }}
                        </button>
                    </div>

                    <div v-if="!splitMode && payMode !== 'complimentary'" class="seg">
                        <button :class="{ on: payMode === 'cash' }" @click="payMode = 'cash'">{{ t('pay.cash') }}</button>
                        <button :class="{ on: payMode === 'bkash' }" @click="payMode = 'bkash'">{{ t('pay.bkash') }}</button>
                        <button :class="{ on: payMode === 'nagad' }" @click="payMode = 'nagad'">{{ t('pay.nagad') }}</button>
                        <button :class="{ on: payMode === 'credit' }" @click="payMode = 'credit'">{{ t('pay.credit') }}</button>
                    </div>
                    <div v-if="!splitMode && isOwner && payMode !== 'complimentary'" style="margin-top:8px">
                        <button type="button" class="btn sm ghost" style="color:var(--gold2);border-color:var(--gold2)" @click="payMode = 'complimentary'">🎁 {{ t('pay.complimentary') }}</button>
                    </div>
                    <div v-if="payMode === 'complimentary'" class="card" style="margin-top:8px;background:var(--goldSoft);border-color:var(--gold2)">
                        <div style="font-size:13px;font-weight:700;color:var(--gold2)">🎁 {{ t('pay.complimentaryActiveHint') }}</div>
                        <button type="button" class="btn sm ghost" style="margin-top:8px" @click="payMode = 'cash'">{{ t('common.cancel') }}</button>
                    </div>

                    <div v-else style="margin-top:6px">
                        <div style="display:flex;gap:8px;margin-bottom:6px">
                            <div style="flex:1">
                                <label style="font-size:12px">{{ t('pay.cash') }}</label>
                                <input v-model.number="splitAmounts.cash" type="number" inputmode="numeric" placeholder="0" min="0">
                            </div>
                            <div style="flex:1">
                                <label style="font-size:12px">{{ t('pay.bkash') }}</label>
                                <input v-model.number="splitAmounts.bkash" type="number" inputmode="numeric" placeholder="0" min="0">
                            </div>
                            <div style="flex:1">
                                <label style="font-size:12px">{{ t('pay.nagad') }}</label>
                                <input v-model.number="splitAmounts.nagad" type="number" inputmode="numeric" placeholder="0" min="0">
                            </div>
                        </div>
                        <div v-if="splitRemainder > 0" style="font-size:13px;font-weight:700;color:var(--rose)">
                            {{ t('pos.splitDue') }}: {{ money(splitRemainder) }}
                            <div v-if="!customerPhone.trim()" style="font-size:12px;font-weight:600;margin-top:2px">{{ t('pos.splitNeedsPhone') }}</div>
                        </div>
                    </div>

                    <!-- bring-your-own-key gateway — only appears once the shop owner has actually connected a provider (More.vue); manual cash/bkash/nagad/credit above always still works regardless -->
                    <div v-if="activeGatewayProviders.length && !splitMode" style="margin-top:10px">
                        <div style="font-size:11px;font-weight:700;color:var(--dim);text-transform:uppercase;margin-bottom:6px">{{ t('pos.payOnlineLabel') }}</div>
                        <div class="btnrow">
                            <button
                                v-for="p in activeGatewayProviders" :key="p" type="button" class="btn sm ghost" style="flex:1;text-transform:capitalize"
                                :disabled="gatewaySubmitting" @click="payViaGateway(p)"
                            >{{ gatewaySubmitting ? '...' : p }}</button>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label>{{ t('pos.overallDiscount') }}</label>
                    <input v-model.number="discount" type="number" inputmode="numeric" placeholder="0" min="0">
                </div>

                <div v-if="hasPromotions" class="field">
                    <label>{{ t('pos.couponCode') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.couponCodeHint') }}</span></label>
                    <input v-model="couponCode" style="text-transform:uppercase" :placeholder="t('pos.couponCodePlaceholder')">
                </div>

                <div v-if="cartNeedsPrescription" class="field">
                    <label style="color:var(--rose)">℞ {{ t('pos.prescriptionCheckLabel') }}</label>
                    <textarea v-model="prescriptionNote" rows="2" :placeholder="t('pos.prescriptionNotePlaceholder')"></textarea>
                </div>

                <div v-if="errorMsg" class="card" style="background:var(--roseSoft);color:var(--rose);margin-bottom:14px;font-size:13px">{{ errorMsg }}</div>

                <!-- subtotal/total + checkout stay pinned to the bottom of this
                     sheet as it scrolls past customer/payment/discount fields —
                     the number and button a cashier needs most are never
                     scrolled out of view -->
                <div class="sticky-footer">
                    <div class="card" style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut);font-size:13.5px"><span>{{ t('pos.subtotal') }}</span><b>{{ money(subtotal) }}</b></div>
                        <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut);font-size:13.5px"><span>{{ t('pos.overallDiscount') }}</span><b>− {{ money(discount || 0) }}</b></div>
                        <div v-if="serviceCharge > 0" style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut);font-size:13.5px"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(serviceCharge) }}</b></div>
                    </div>

                    <!-- grand total gets its own bold, full-width bar — the one
                         number a cashier absolutely must not misread before
                         collecting payment, so it shouldn't share visual weight
                         with the subtotal/discount breakdown above it -->
                    <div class="grand-total-bar">
                        <span>{{ t('pos.grandTotal') }}</span>
                        <b>{{ money(total) }}</b>
                    </div>

                    <button class="btn" :disabled="submitting" @click="submitCheckout">
                        {{ submitting ? t('pos.processing') : `${t('pos.checkoutButton')} ${money(total)}` }}
                    </button>
                    <button class="btn ghost" style="margin-top:10px" :disabled="submitting" @click="holdCart">
                        ⏸️ {{ t('pos.holdCart') }}
                    </button>
                </div>
            </div>
            <div v-else class="empty"><div class="big">🛒</div>{{ t('pos.cartEmpty') }}</div>
        </Sheet>

        <!-- held carts picker -->
        <Sheet v-model="heldSheet" :title="t('pos.heldCarts')">
            <div v-for="h in heldCarts" :key="h.id" class="row" style="cursor:pointer" @click="resumeHeldCart(h.id)">
                <div class="ava">⏸️</div>
                <div class="mid">
                    <b>{{ h.label || t('pos.heldCartDefaultLabel', { id: h.id }) }}</b>
                    <span>{{ h.created_at }}</span>
                </div>
                <button class="btn sm ghost" style="width:auto" @click.stop="deleteHeldCart(h.id)">{{ t('common.delete') }}</button>
            </div>
            <div v-if="!heldCarts.length" class="empty"><div class="big">📋</div>{{ t('pos.noHeldCarts') }}</div>
        </Sheet>

        <!-- weight/volume quick-entry — the only way a loose kg/litre product gets added to (or edited in) the cart -->
        <Sheet v-model="weightSheet" :title="weightProduct?.name">
            <div v-if="weightProduct" class="card" style="margin-bottom:14px;text-align:center">
                <div style="font-size:32px">{{ weightProduct.emoji }}</div>
                <div style="font-weight:700;margin-top:4px">{{ money(weightProduct.price) }} / {{ weightBigUnit(weightProduct) }}</div>
                <div style="color:var(--mut);font-size:12.5px;margin-top:2px">{{ t('stock.currentStock') }} {{ formatWeightQty(weightProduct, weightProduct.stock) }}</div>
            </div>

            <div class="field">
                <label>{{ t('pos.weightEntryLabel') }}</label>
                <div style="display:flex;gap:8px">
                    <input v-model="weightValue" type="number" inputmode="decimal" step="any" style="flex:1" autofocus>
                    <button class="btn sm ghost" style="width:auto;padding:0 16px" @click="toggleWeightEntryUnit">{{ weightEntryUnit }}</button>
                </div>
            </div>

            <div v-if="weightProduct" style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 16px">
                <button v-for="g in WEIGHT_PRESETS_G" :key="g" class="upill" @click="pickWeightPreset(g)">
                    {{ g < 1000 ? `${g} ${weightSmallUnit(weightProduct)}` : `${g / 1000} ${weightBigUnit(weightProduct)}` }}
                </button>
            </div>

            <button class="btn" :disabled="!weightValue || Number(weightValue) <= 0" @click="confirmWeightEntry">{{ t('pos.addToCartButton') }}</button>
            <button v-if="weightProduct && cart.some((l) => l.product_id === weightProduct.id && !l.product_variant_id && !l.product_unit_id)" class="btn ghost" style="margin-top:10px;color:var(--rose);border-color:var(--rose)" @click="removeWeightLine">{{ t('common.delete') }}</button>
        </Sheet>

        <!-- receipt sheet — everything except #printable-memo is marked
             no-print, since printing this sheet must only ever produce the
             receipt itself, never the "sale complete" celebration chrome
             around it (see the @media print block below for why that needs
             explicit marking, not just hiding #app) -->
        <Sheet v-model="receiptOpen">
            <div class="no-print" style="text-align:center;margin-bottom:6px"><span style="font-size:34px">🎉</span></div>
            <div class="no-print shttl" style="text-align:center">{{ t('pos.billComplete') }}</div>
            <div v-if="lastSale" class="no-print shsub" style="text-align:center">{{ lastSale.invoice_no }} • {{ money(lastSale.total) }}</div>

            <div v-if="lastSale" id="printable-memo" class="receipt">
                <img v-if="shop?.logo_url" :src="shop.logo_url" style="max-width:100px;display:block;margin:0 auto 8px">
                <h3>{{ shop?.name || 'Zaylotix POS' }}</h3>
                <div class="rc-sub">
                    📞 {{ shop?.phone }}
                    <template v-if="shop?.bin_no && lastSale.vat > 0"><br>BIN: {{ shop.bin_no }}</template>
                    <br>মেমো — {{ lastSale.invoice_no }}
                </div>
                <div v-for="l in lastSale.items" :key="l.id" class="rc-l">
                    <span>
                        {{ l.product_name }}{{ (l.unit_label || l.variant_label) ? ' (' + (l.unit_label || l.variant_label) + ')' : '' }} ×{{ l.qty }}
                        <template v-if="l.discount > 0"><br><span style="color:#c0392b;font-size:10.5px">ছাড় −{{ money(l.discount) }}</span></template>
                    </span>
                    <b>{{ money(l.price * l.qty - (l.discount || 0)) }}</b>
                </div>
                <div v-if="lastSale.discount > 0" class="rc-l"><span>ওভারঅল ছাড়{{ lastSale.coupon_code ? ` (${lastSale.coupon_code})` : '' }}</span><b>− {{ money(lastSale.discount) }}</b></div>
                <div v-if="lastSale.service_charge > 0" class="rc-l"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(lastSale.service_charge) }}</b></div>
                <div v-if="lastSale.vat > 0" class="rc-l"><span>VAT</span><b>{{ money(lastSale.vat) }}</b></div>
                <div class="rc-t"><span>মোট</span><span>{{ money(lastSale.total) }}</span></div>
                <template v-if="lastSale.payments?.length > 1 || (lastSale.payments?.length && lastSale.payment_mode === 'split')">
                    <div v-for="p in lastSale.payments" :key="p.id" class="rc-l" style="font-size:10.5px;color:#555">
                        <span>{{ p.method }}</span><span>{{ money(p.amount) }}</span>
                    </div>
                </template>
                <div v-if="lastSale.payment_mode === 'credit' || lastSale.payment_mode === 'split'" style="text-align:center;color:#c0392b;font-weight:700;margin-top:6px;font-size:11px">
                    বাকি — {{ money(lastSale.total - (lastSale.payments || []).reduce((s, p) => s + Number(p.amount), 0)) }}
                </div>
                <div v-if="lastSale.points_redeemed || lastSale.points_earned" style="text-align:center;color:#555;margin-top:6px;font-size:10.5px">
                    <span v-if="lastSale.points_redeemed">{{ t('pos.pointsRedeemedReceipt', { n: lastSale.points_redeemed }) }} </span>
                    <span v-if="lastSale.points_earned">{{ t('pos.pointsEarnedReceipt', { n: lastSale.points_earned }) }}</span>
                </div>
                <div class="rc-f">{{ shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏' }}</div>
                <div style="display:flex;justify-content:center;margin-top:8px"><svg id="pos-receipt-barcode-svg"></svg></div>
                <div style="display:flex;flex-direction:column;align-items:center;margin-top:6px;gap:2px">
                    <canvas id="pos-receipt-rating-qr"></canvas>
                    <div style="font-size:9px;color:#666">{{ t('sales.rateHint') }}</div>
                </div>
                <div class="rc-brand">
                    <template v-if="platformLogoUrl"><img :src="platformLogoUrl" alt="Zaylotix"></template>
                    <template v-else>A Zaylotix product · zaylotix.com</template>
                </div>
            </div>

            <div class="no-print btnrow" style="margin-top:16px">
                <button v-if="features.includes('memo_whatsapp')" class="btn wa" style="flex:1" @click="sendMemoWA">📤 WhatsApp</button>
                <button v-if="features.includes('memo_print')" class="btn ghost" style="flex:1" @click="printMemo">{{ t('pos.print') }}</button>
            </div>
            <button class="no-print btn ghost" style="margin-top:10px" @click="receiptOpen = false">{{ t('pos.newSale') }}</button>
        </Sheet>
    </AppLayout>
</template>

<style>
/* 58mm is the width of the cheap thermal "POS58" receipt printers most
   small Bangladeshi shops actually own — narrower type/padding than the
   in-app preview so a full line of text still fits on the paper roll. */
@media print {
    @page { size: 58mm auto; margin: 0; }
    /* #printable-memo lives inside <Sheet>, which <Teleport>s to <body> —
       it's a sibling of #app (the whole rest of the app UI), not a child.
       Hiding #app removes it from the layout entirely, so there's nothing
       left to paginate except the receipt itself. (A `visibility: hidden`
       trick was here before — it doesn't remove hidden elements from the
       page's layout flow, so with a fixed 40mm-tall @page elsewhere in this
       app that produced dozens of near-blank pages; see BarcodeLabels/Index.vue.) */
    #app { display: none !important; }
    /* #sheet itself is a fixed-position, height-capped, scrollable modal
       box (it's a bottom sheet on screen) — #printable-memo lives inside
       it, so those constraints must be neutralized too, or the browser
       prints only whatever fit inside that 90vh scroll viewport instead of
       flowing the full receipt down the page. This is what actually let
       the "🎉 Sale Complete!" chrome show up in a printed/PDF-exported
       receipt: marking those elements no-print alone doesn't help if the
       box around them still clips/positions everything as a fixed overlay. */
    #sheet {
        position: static; max-height: none; overflow: visible;
        width: auto; max-width: none; padding: 0; border: 0; border-radius: 0;
        /* left:50% + this translateX is how #sheet centers itself as a
           fixed-position bottom sheet — position:static makes `left` inert,
           but the transform keeps applying regardless of position, shifting
           the whole (now full-width, static-flow) element half its own
           width off to the side. On a narrow 58mm print page that's enough
           to push the receipt entirely off the printable area — the actual
           cause of the blank printed page. */
        left: auto; transform: none;
    }
    #scrim { display: none !important; }
    #printable-memo {
        width: 58mm; padding: 2mm 3mm;
        border-radius: 0; font-size: 11px;
    }
    #printable-memo h3 { font-size: 13px; }
}
</style>
