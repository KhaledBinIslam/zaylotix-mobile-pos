<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import HowToHint from '@/Components/HowToHint.vue';
import ReceiptMemo from '@/Components/ReceiptMemo.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { useOfflineCatalog } from '@/composables/useOfflineCatalog';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';
import { buildOfflineSaleSnapshot } from '@/support/offlineSaleSnapshot';
import { buildReceiptWhatsAppText, whatsAppUrlFor } from '@/support/receiptMessage';
import { variantPriceBounds, variantStockTotal } from '@/support/productDisplay';
import { normalizeSearch } from '@/support/searchNormalize';
import { bestMatchTier, levenshtein } from '@/support/searchMatch';
import { usePermissions } from '@/composables/usePermissions';
import { scanBoxSize } from '@/support/scanBoxSize';

/**
 * A dedicated, clothing-specific POS — category tabs -> product grid ->
 * color/size variant picker -> cart, mirroring the two-step feel of the
 * restaurant Tables/Order screens (pick a context, then build the sale)
 * but without a table/KOT step, since a clothing sale has no kitchen to
 * route anything to. Deliberately leaner than the shared Pos/Index.vue:
 * no coupon/loyalty/wholesale/quotation/held-cart support here (those stay
 * on the shared screen) — this page exists to make the one thing a
 * clothing shop does constantly (pick an item, pick its color+size, sell
 * it) as few taps as possible. Reuses the exact same checkout endpoint
 * (PosController::checkout) and stock math as every other vertical, so a
 * variant's stock is always correct regardless of which POS screen sold it.
 */
const props = defineProps({
    products: Array,
    categories: Array,
    // see Pos/Index.vue's defineProps for the full explanation —
    // 'search' only for a shop over config('pos.full_catalog_limit')
    catalogMode: { type: String, default: 'full' },
    productCount: { type: Number, default: 0 },
    salesMode: String,
    activeGatewayProviders: { type: Array, default: () => [] },
});

const page = usePage();
const shop = computed(() => page.props.shop);
const paperClass = computed(() => 'paper-' + (shop.value?.memo_settings?.paper_width || '58mm').replace('mm', ''));
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
const features = computed(() => page.props.features || []);
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const hasProductVariants = computed(() => features.value.includes('product_variants'));
const hasLowStockAlerts = computed(() => features.value.includes('low_stock_alerts'));
const { toast } = useToast();
const { t } = useI18n();
const offlineSync = useOfflineSync();
const catalog = useOfflineCatalog();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const canScan = computed(() => props.salesMode === 'scan' || props.salesMode === 'both');

const q = ref('');
const cat = ref('all');
const cart = ref([]); // [{product_id, product_variant_id, qty, discount}]
const cartOpen = ref(false);
const receiptOpen = ref(false);
const lastSale = ref(null);
const errorMsg = ref('');
const submitting = ref(false);

// see Pos/Index.vue's identical block for the full explanation — search
// results merge into props.products (accumulating, never replaced) so
// addToCart()/cart-line lookups below keep resolving a product by id even
// after the cashier's search query changes.
const searchResults = ref([]);
if (props.catalogMode === 'search') {
    catalog.startBackgroundSync(props.productCount);
    watch([q, cat], async () => {
        const results = await catalog.search(q.value, cat.value);
        searchResults.value = results;
        for (const p of results) {
            if (!props.products.some((existing) => existing.id === p.id)) props.products.push(p);
        }
    }, { immediate: true });
}

// See Pos/Index.vue's identical comment/pattern (docs/FIX_PLAN.md's
// search-normalization fix) — same reasoning, same shared helpers.
const normalizedCatalog = computed(() => props.products.map((p) => ({
    product: p,
    fields: [normalizeSearch(p.name), normalizeSearch(p.name_en || '')],
})));

const filtered = computed(() => {
    if (props.catalogMode === 'search') return searchResults.value;

    const inCategory = normalizedCatalog.value.filter(({ product: p }) => cat.value === 'all' || p.category_id === cat.value);
    if (!q.value) return inCategory.map(({ product }) => product);

    const normalizedQ = normalizeSearch(q.value);

    return inCategory
        .map(({ product, fields }) => ({ product, tier: bestMatchTier(normalizedQ, fields, { barcodeExact: (product.barcode || '') === q.value }) }))
        .filter((row) => row.tier !== null)
        .sort((a, b) => a.tier - b.tier)
        .map((row) => row.product);
});

function onSearchInput(e) {
    if (e.isComposing) return;
    q.value = e.target.value;
}

const { hasPerm } = usePermissions();

// See Pos/Index.vue's identical computed/comment (ধাপ ২.চ).
const didYouMeanSuggestions = computed(() => {
    if (props.catalogMode === 'search' || filtered.value.length || !q.value.trim()) return [];

    const normalizedQ = normalizeSearch(q.value);
    const maxDistance = Math.max(3, Math.ceil(normalizedQ.length * 0.6));

    return normalizedCatalog.value
        .map(({ product, fields }) => ({ product, distance: Math.min(...fields.filter(Boolean).map((f) => levenshtein(normalizedQ, f))) }))
        .filter((row) => row.distance <= maxDistance)
        .sort((a, b) => a.distance - b.distance)
        .slice(0, 3)
        .map((row) => row.product);
});

function productOf(id) {
    return props.products.find((p) => p.id === id);
}
function variantOf(product, productVariantId) {
    return productVariantId ? product.variants?.find((v) => v.id === productVariantId) : null;
}
// matches ProductVariant::label() and the shared Pos/Index.vue exactly —
// this is what the receipt actually prints (server-computed, stored on the
// sale item), so the cart/picker here must show the same order/format or
// a customer could see one label while shopping and a different one on
// the printed memo
function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}
function stockLevel(stock, reorderPoint) {
    const s = Number(stock);
    if (s <= 0) return 'out';
    const threshold = Number(reorderPoint) > 0 ? Number(reorderPoint) : 6;
    return s <= threshold ? 'low' : 'ok';
}

// A variant product's card only ever showed its base `price` — never a
// range, even when sizes/colors actually cost different amounts (see
// docs/FIX_PLAN.md's "price/stock missing during order/sale" fix, and
// support/productDisplay.js for the shared, unit-tested numeric logic).
// Mirrors the existing "only show a variant's own price when it differs"
// rule one level up (the variant picker below) — same instinct, applied
// to the card's own header number.
function variantPriceRange(p) {
    const { min, max } = variantPriceBounds(p);
    return min === max ? money(min) : `${money(min)}–${money(max)}`;
}

/* ---------------- variant picker sheet ---------------- */
const variantSheetOpen = ref(false);
const variantSheetProduct = ref(null);
// set only when the picker was opened from an existing cart line's "change"
// tap rather than from the product grid — pickVariant() then updates that
// line in place instead of adding a new one, so a wrong color/size grabbed
// in a hurry doesn't need a remove-and-re-add round trip
const editingCartLine = ref(null);

function openVariantPicker(product, lineToEdit = null) {
    if (hasProductVariants.value && product.variants?.length) {
        // both this and the cart are separate <Sheet> instances (each its
        // own Teleport to <body> reusing the same #sheet id/z-index) — with
        // the cart left open, this one would mount stacked BEHIND it and
        // "change" would look like it does nothing. Closing the cart first
        // (and reopening it after a pick, see pickVariant()) keeps exactly
        // one sheet on screen at a time, same as every other flow here.
        if (lineToEdit) cartOpen.value = false;
        variantSheetProduct.value = product;
        editingCartLine.value = lineToEdit;
        variantSheetOpen.value = true;
        return;
    }
    addToCart(product.id, null);
}
function pickVariant(v) {
    const product = variantSheetProduct.value;
    if (editingCartLine.value) {
        changeCartLineVariant(editingCartLine.value, product.id, v.id);
    } else {
        addToCart(product.id, v.id);
    }
    variantSheetOpen.value = false;
}

// covers BOTH exits from the picker: picking a variant (pickVariant() above
// already flipped this to false) and dismissing it via the scrim/backdrop
// tap, which closes the Sheet directly through v-model without ever calling
// pickVariant() — either way, if we closed the cart to show this picker,
// re-show it now rather than leaving the cashier looking at an empty page.
watch(variantSheetOpen, (open) => {
    if (!open && editingCartLine.value) {
        cartOpen.value = true;
        editingCartLine.value = null;
    }
});

/** Moves an existing cart line to a different color/size, capping qty to whatever the new variant can actually cover and merging into a matching line if one already exists. */
function changeCartLineVariant(line, productId, newVariantId) {
    if (newVariantId === line.product_variant_id) return;
    const product = productOf(productId);
    const newVariant = variantOf(product, newVariantId);
    if (!newVariant) return;

    // stock already claimed by OTHER cart lines for the target variant —
    // this line's own qty is moving away from its old variant, not adding on top
    const claimedByOthers = cart.value.reduce((s, l) => s + (l !== line && l.product_variant_id === newVariantId ? l.qty : 0), 0);
    const available = newVariant.stock - claimedByOthers;
    if (available <= 0) {
        toast('❌ ' + t('pos.notEnoughStock') + ' ' + product.name + ' (' + variantLabel(newVariant) + ')');
        return;
    }
    const qtyToMove = Math.min(line.qty, available);

    const existingLine = cart.value.find((l) => l !== line && l.product_variant_id === newVariantId);
    if (existingLine) {
        existingLine.qty += qtyToMove;
        cart.value = cart.value.filter((l) => l !== line);
    } else {
        line.product_variant_id = newVariantId;
        line.qty = qtyToMove;
    }
    toast('✅ ' + product.name + ' (' + variantLabel(newVariant) + ')');
}

function addToCart(productId, productVariantId) {
    const product = productOf(productId);
    if (!product) return;

    if (productVariantId) {
        const v = variantOf(product, productVariantId);
        if (!v) return;
        const alreadyInCart = cart.value.reduce((s, l) => s + (l.product_variant_id === productVariantId ? l.qty : 0), 0);
        if (v.stock <= alreadyInCart) {
            toast('❌ ' + t('pos.notEnoughStock') + ' ' + product.name + ' (' + variantLabel(v) + ')');
            return;
        }
        const line = cart.value.find((l) => l.product_variant_id === productVariantId);
        if (line) line.qty++;
        else cart.value.push({ product_id: productId, product_variant_id: productVariantId, qty: 1, discount: 0 });
        toast('✅ ' + product.name + ' (' + variantLabel(v) + ')');
        return;
    }

    const alreadyInCartBase = cart.value.reduce((s, l) => s + (!l.product_variant_id && l.product_id === productId ? l.qty : 0), 0);
    if (product.stock <= alreadyInCartBase) {
        toast('❌ ' + t('pos.notEnoughStock') + ' ' + product.name);
        return;
    }
    const line = cart.value.find((l) => l.product_id === productId && !l.product_variant_id);
    if (line) line.qty++;
    else cart.value.push({ product_id: productId, product_variant_id: null, qty: 1, discount: 0 });
    toast('✅ ' + product.name);
}

function lineProduct(l) {
    return productOf(l.product_id);
}
function lineVariant(l) {
    return l.product_variant_id ? variantOf(lineProduct(l), l.product_variant_id) : null;
}
// discount_price fix (see Pos/Index.vue's unitOf() for the full story,
// docs/FIX_PLAN.md) — only the no-variant base-product line is affected;
// a variant's own price is independent and untouched.
function lineUnitPrice(l) {
    const v = lineVariant(l);
    const p = lineProduct(l);
    if (v) return Number(v.price ?? p?.price ?? 0);
    return Number(p?.discount_price ?? p?.price ?? 0);
}
function lineTotal(l) {
    return Math.max(0, lineUnitPrice(l) * l.qty - (l.discount || 0));
}
function incrementLine(l) {
    addToCart(l.product_id, l.product_variant_id);
}
function decrementLine(l) {
    l.qty--;
    if (l.qty <= 0) cart.value = cart.value.filter((x) => x !== l);
}
function removeLine(l) {
    cart.value = cart.value.filter((x) => x !== l);
}
// reported: a cart line's quantity could only be nudged one tap at a time
// — no way to type a number directly, painful for a large quantity
function setQty(l, value) {
    const n = Math.floor(Number(value)) || 0;
    if (n <= 0) { cart.value = cart.value.filter((x) => x !== l); return; }
    l.qty = n;
}

const cartCount = computed(() => cart.value.reduce((s, l) => s + l.qty, 0));
const subtotal = computed(() => cart.value.reduce((s, l) => s + lineTotal(l), 0));
// complimentary forces the server to charge nothing (discount = subtotal,
// see PosController::performCheckout) — the displayed total must reflect
// that the instant the owner picks "🎁 Complimentary", not just after the
// receipt comes back, or the sheet keeps showing a price that's never
// actually charged
const effectiveDiscount = computed(() => (payMode.value === 'complimentary' ? subtotal.value : (discount.value || 0)));
// Display-only breakdown for the checkout sheet — see Pos/Index.vue's
// identical pair for why: `subtotal` is already net of each line's own
// discount, so showing it next to "Discount: -৳0" whenever only
// per-line discounts were used made it look like nothing was discounted.
const grossSubtotal = computed(() => cart.value.reduce((s, l) => s + lineUnitPrice(l) * l.qty, 0));
const totalDiscount = computed(() => (grossSubtotal.value - subtotal.value) + effectiveDiscount.value);
const serviceCharge = computed(() => {
    const rate = shop.value?.service_charge_rate;
    if (rate === null || rate === undefined) return 0;
    return Math.round(Math.max(0, subtotal.value - effectiveDiscount.value) * Number(rate) / 100 * 100) / 100;
});
const total = computed(() => Math.max(0, subtotal.value - effectiveDiscount.value) + serviceCharge.value);

/* ---------------- checkout ---------------- */
const discount = ref(0);
const payMode = ref('cash');
const splitMode = ref(false);
const splitAmounts = ref({ cash: '', bkash: '', nagad: '' });
const customerPhone = ref('');
const customerName = ref('');
const customerLookup = ref(null);

// Snapshot the in-progress cart to sessionStorage and restore it on mount
// — mirrors Pos/Index.vue's own CART_RECOVERY_KEY mechanism (see its
// comment for the full root-cause writeup: Inertia's global popstate
// handler forces a full remount of this component, wiping every plain
// ref, whenever a hardware back press closes a Sheet — not just here, but
// Clothing/Pos.vue never had any recovery at all, so that remount used to
// lose the cart outright instead of just needing this restore.
const CART_RECOVERY_KEY = 'zaylotix_clothing_pos_cart_recovery';
function saveCartForRecovery() {
    try {
        sessionStorage.setItem(CART_RECOVERY_KEY, JSON.stringify({
            cart: cart.value, discount: discount.value,
            customerPhone: customerPhone.value, customerName: customerName.value, payMode: payMode.value,
            splitMode: splitMode.value, splitAmounts: splitAmounts.value,
        }));
    } catch { /* storage unavailable */ }
}
onMounted(() => {
    let saved;
    try { saved = JSON.parse(sessionStorage.getItem(CART_RECOVERY_KEY)); } catch { saved = null; }
    if (!saved) return;
    sessionStorage.removeItem(CART_RECOVERY_KEY);
    cart.value = (saved.cart || []).filter((l) => productOf(l.product_id));
    discount.value = saved.discount || 0;
    customerPhone.value = saved.customerPhone || '';
    customerName.value = saved.customerName || '';
    payMode.value = saved.payMode || 'cash';
    splitMode.value = saved.splitMode || false;
    splitAmounts.value = saved.splitAmounts || { cash: '', bkash: '', nagad: '' };
    if (cart.value.length) { cartOpen.value = true; toast(t('pos.cartRestored')); }
});
watch([cart, discount, customerPhone, customerName, payMode, splitMode, splitAmounts], saveCartForRecovery, { deep: true });

const splitTendered = computed(() => ['cash', 'bkash', 'nagad'].reduce((s, m) => s + (Number(splitAmounts.value[m]) || 0), 0));
const splitRemainder = computed(() => Math.max(0, Math.round((total.value - splitTendered.value) * 100) / 100));

// Prefill cash with the current total when entering split mode, and KEEP
// it tracking the total (e.g. the overall discount changing after the
// fact) as long as the cashier hasn't actually typed into that field
// themselves — reported: changing the discount after opening split mode
// left cash showing the pre-discount amount, since this used to be a
// one-time copy only applied at the moment split mode was toggled on.
// Clears on exit so a stale amount doesn't resurface next time it's
// toggled on.
const splitCashAutofilled = ref(false);
function toggleSplitMode() {
    splitMode.value = !splitMode.value;
    if (splitMode.value) {
        splitAmounts.value = { cash: total.value || '', bkash: '', nagad: '' };
        splitCashAutofilled.value = true;
    } else {
        splitAmounts.value = { cash: '', bkash: '', nagad: '' };
        splitCashAutofilled.value = false;
    }
}
watch(total, (newTotal) => {
    if (splitMode.value && splitCashAutofilled.value) {
        splitAmounts.value.cash = newTotal || '';
    }
});
function onSplitCashInput() {
    splitCashAutofilled.value = false;
}

function buildPayments() {
    if (splitMode.value) {
        return ['cash', 'bkash', 'nagad']
            .filter((m) => Number(splitAmounts.value[m]) > 0)
            .map((m) => ({ method: m, amount: Number(splitAmounts.value[m]) }));
    }
    if (payMode.value === 'credit' || payMode.value === 'complimentary') return [];
    // a discount that fully covers the subtotal legitimately zeroes the
    // total — see Pos/Index.vue's buildPayments() for why this must not
    // send a {amount: 0} payment line (server rejects it, min:0.01)
    if (total.value <= 0) return [];
    return [{ method: payMode.value, amount: total.value }];
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// Real gap, found while building the Pending Sales sheet (docs/FIX_PLAN.md
// Phase 12): this screen's offline payload had no client_uuid at all —
// zero replay-idempotency protection (Phase 1's fix never reached this
// screen), no shop_id pin (the admin-impersonation wrong-shop bug Phase 11
// fixed on Pos/Index.vue could still silently misfire here), and no
// product name/barcode/price snapshot (every offline failure from this
// screen showed only "unknown product"). Mirrors Pos/Index.vue's own
// checkoutUuid()/lineSnapshot() exactly — same idempotency key convention,
// same diagnostic-only snapshot shape.
let pendingCheckoutUuid = null;
function checkoutUuid() {
    if (!pendingCheckoutUuid) {
        pendingCheckoutUuid = (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
    }
    return pendingCheckoutUuid;
}
function lineSnapshot(l) {
    const p = props.products.find((pp) => pp.id === l.product_id);
    const variant = p?.variants?.find((v) => v.id === l.product_variant_id);
    return {
        product_name: p?.name || '',
        product_barcode: p?.barcode || '',
        variant_label: variant ? [variant.size, variant.color].filter(Boolean).join(' / ') : '',
        unit_label: null,
        unit_price: variant?.price ?? p?.price ?? null,
    };
}

function resetCartAfterCheckout() {
    pendingCheckoutUuid = null;
    cart.value = [];
    discount.value = 0;
    customerPhone.value = '';
    customerName.value = '';
    customerLookup.value = null;
    splitMode.value = false;
    splitAmounts.value = { cash: '', bkash: '', nagad: '' };
    payMode.value = 'cash';
    cartOpen.value = false;
}

async function submitCheckout() {
    if (!cart.value.length || submitting.value) return;
    // Same "বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন" rule PosController::
    // performCheckout() enforces server-side — see docs/FIX_PLAN.md's
    // pending-sales-sheet fix and Pos/Index.vue's identical guard.
    if (dueNeedsCustomerPhone()) {
        errorMsg.value = t('pos.splitNeedsPhone');
        return;
    }
    submitting.value = true;
    errorMsg.value = '';

    const payload = {
        client_uuid: checkoutUuid(),
        shop_id: shop.value?.id ?? null,
        shop_name: shop.value?.name ?? null,
        items: cart.value.map((l) => ({ product_id: l.product_id, product_variant_id: l.product_variant_id, qty: l.qty, discount: l.discount || 0, ...lineSnapshot(l) })),
        discount: discount.value || 0,
        complimentary: payMode.value === 'complimentary',
        payments: buildPayments(),
        customer_phone: customerPhone.value,
        customer_name: customerName.value,
    };

    try {
        // fetchWithSessionRetry: one safe, automatic retry on a 401 or a
        // stale-CSRF 419 (see its own docblock and docs/SESSION_FIX.md) —
        // this screen was missing it entirely before, unlike Pos/Index.vue.
        const res = await fetchWithSessionRetry(route('app.pos.checkout'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
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
        // A real connectivity failure never loses the sale — it's queued
        // locally and replayed automatically once the connection returns
        // (see useOfflineSync), exactly like Pos/Index.vue already does;
        // this screen was missing that safety net entirely before, only
        // showing an error and leaving the cart to be lost if the cashier
        // navigated away or closed the tab while still offline. Anything
        // that ISN'T a genuine connectivity failure (a JSON parse issue, a
        // bug above) still surfaces as a real error instead of being
        // silently swallowed into an offline queue it doesn't belong in.
        if (!navigator.onLine || e instanceof TypeError) {
            lastSale.value = buildOfflineSaleSnapshot(payload);
            await offlineSync.queueSale(payload, { userId: page.props.auth?.user?.id, userName: page.props.auth?.user?.name });
            resetCartAfterCheckout();
            receiptOpen.value = true;
            toast(t('pos.savedOffline'));
        } else {
            console.error('Checkout failed:', e);
            errorMsg.value = `${t('pos.checkoutError')} (${e?.message || e})`;
        }
    } finally {
        submitting.value = false;
    }
}

const gatewaySubmitting = ref(false);
async function payViaGateway(provider) {
    if (!cart.value.length || gatewaySubmitting.value) return;
    gatewaySubmitting.value = true;
    errorMsg.value = '';
    try {
        const res = await fetchWithSessionRetry(route('app.gatewayCheckout.initiate', provider), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify({
                items: cart.value.map((l) => ({ product_id: l.product_id, product_variant_id: l.product_variant_id, qty: l.qty, discount: l.discount || 0 })),
                discount: discount.value || 0,
                customer_phone: customerPhone.value,
                customer_name: customerName.value,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.redirect_url) {
            errorMsg.value = data.message || t('pos.checkoutError');
            return;
        }
        window.location.href = data.redirect_url;
    } catch (e) {
        errorMsg.value = t('pos.networkError');
    } finally {
        gatewaySubmitting.value = false;
    }
}

let lookupTimer = null;
watch(customerPhone, (val) => {
    clearTimeout(lookupTimer);
    customerLookup.value = null;
    const phone = (val || '').trim();
    if (phone.length < 6) return;
    lookupTimer = setTimeout(async () => {
        try {
            const res = await fetch(route('app.customers.lookup') + '?phone=' + encodeURIComponent(phone), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            customerLookup.value = data;
            if (data.found && !customerName.value.trim()) customerName.value = data.name;
        } catch (e) { /* not critical to checkout */ }
    }, 400);
});

/* ---------------- barcode scanner (camera + hardware) ---------------- */
const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
let lastScanCode = '';
let lastScanAt = 0;

async function openScanner() {
    if (!canScan.value) { toast('❌ ' + t('pos.scannerDisabled')); return; }
    scannerOpen.value = true;
    scanHint.value = t('pos.scanHintDefault');
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('clothing-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: scanBoxSize, videoConstraints: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } } },
            (text) => {
                scanHint.value = '✅ ' + t('pos.scannedCode') + ': ' + text;
                handleBarcode(text, (msg) => (scanHint.value = msg));
            },
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
        try { await html5Qrcode.stop(); html5Qrcode.clear(); } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}

async function handleBarcode(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return;
    lastScanCode = decodedText;
    lastScanAt = now;
    if (navigator.vibrate) navigator.vibrate(40);

    const code = decodedText.trim();

    // docs/ARTICLE_SERIES_PLAN.md §10 — a short printed-symbol code
    // (ZV{variantId}), decoupled from the full descriptive text still
    // printed on the label below it. See Pos/Index.vue's identical tier.
    const zv = code.match(/^ZV(\d+)$/);
    if (zv) {
        const vid = Number(zv[1]);
        for (const p of props.products) {
            const v = p.variants?.find((variant) => variant.id === vid);
            if (v) {
                addToCart(p.id, v.id);
                onFeedback('✅ ' + p.name + ' (' + variantLabel(v) + ')');
                return;
            }
        }
    }

    // a variant's own barcode is more specific than the product's — check
    // it first so a size/color sticker always resolves to that exact one
    for (const p of props.products) {
        const v = p.variants?.find((variant) => variant.barcode === code);
        if (v) {
            addToCart(p.id, v.id);
            onFeedback('✅ ' + p.name + ' (' + variantLabel(v) + ')');
            return;
        }
    }

    const product = props.products.find((p) => p.barcode === code);
    if (product) {
        if (hasProductVariants.value && product.variants?.length) {
            onFeedback('❌ ' + product.name + ' — ' + t('pos.pickVariant'));
            return;
        }
        addToCart(product.id, null);
        onFeedback('✅ ' + product.name);
        return;
    }

    try {
        // see Pos/Index.vue's identical swap — resolveBarcode() tries the
        // server first, then (offline, or that call failed) the local
        // offline cache; same {found, product?, variant_id?} shape either way.
        const data = await catalog.resolveBarcode(code);
        if (data.found) {
            props.products.push(data.product);
            if (data.variant_id) {
                const v = data.product.variants?.find((variant) => variant.id === data.variant_id);
                addToCart(data.product.id, data.variant_id);
                onFeedback('✅ ' + data.product.name + (v ? ' (' + variantLabel(v) + ')' : ''));
            } else if (hasProductVariants.value && data.product.variants?.length) {
                onFeedback('❌ ' + data.product.name + ' — ' + t('pos.pickVariant'));
            } else {
                addToCart(data.product.id, null);
                onFeedback('✅ ' + data.product.name);
            }
        } else {
            onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
        }
    } catch (e) {
        onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
    }
}

useHardwareScanner((code) => handleBarcode(code, (msg) => toast(msg)), canScan);

/* ---------------- receipt ---------------- */
function printMemo() {
    window.print();
}
// invoice barcode + rating QR are rendered by ReceiptMemo.vue itself now

// Mirrors ReceiptMemo.vue's printed memo field-for-field (same
// memo_settings toggles) — see Sales/Show.vue's identical function for the
// full reasoning.
function sendMemoWA() {
    const s = lastSale.value;
    if (!s) return;
    // see Pos/Index.vue's identical guard for why — wa.me needs a live
    // connection to open at all; nothing needs saving separately since
    // this just rebuilds from `s` whenever the button is next tapped.
    if (!navigator.onLine) {
        toast(t('pos.whatsappNeedsInternet'));
        return;
    }
    window.open(whatsAppUrlFor(s, buildReceiptWhatsAppText(shop.value, s, t)), '_blank');
}
</script>

<template>
    <Head :title="t('nav.sell')" />
    <AppLayout active="sell">
        <!-- cart bar pinned to the TOP of the page (not the bottom) — Khaled
             was explicit this should stay in view/up front rather than
             sitting at the bottom where it can get missed while scrolling a
             long product grid; sticky so it stays visible while scrolling,
             not just present on first load -->
        <div v-if="cartCount" style="position:sticky;top:0;z-index:10;margin:-2px -2px 12px;padding:2px">
            <button type="button" class="btn" @click="cartOpen = true">
                <span style="display:flex;align-items:center;gap:8px"><span class="cartcount">{{ cartCount }}</span> {{ t('pos.viewCart') }}</span>
                <span style="margin-left:auto;font-weight:850">{{ money(subtotal) }}</span>
            </button>
        </div>

        <div class="lg:flex lg:gap-6 lg:items-start">
            <aside class="hidden lg:block lg:order-1 lg:w-52 lg:shrink-0 lg:sticky lg:top-6">
                <button class="rest-rail-btn" :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                <button v-for="c in categories" :key="c.id" class="rest-rail-btn" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
            </aside>

            <div class="lg:order-2 lg:flex-1 lg:min-w-0">
                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <input
                        :value="q" @input="onSearchInput" @compositionend="onSearchInput"
                        :placeholder="t('pos.searchPlaceholder')" style="flex:1"
                        autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                    >
                    <button v-if="canScan" class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openScanner">📷</button>
                </div>
                <div v-if="catalogMode === 'search' && !catalog.syncProgress.value.complete" style="font-size:11.5px;color:var(--dim);margin:-6px 0 10px">
                    🔄 {{ t('pos.catalogSyncing') }} ({{ catalog.syncProgress.value.done }}/{{ catalog.syncProgress.value.total }})
                </div>

                <div class="tabbar lg:hidden">
                    <button :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                    <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
                </div>

                <HowToHint screen-key="pos" />

                <div v-if="filtered.length" class="pgrid">
                    <button v-for="p in filtered" :key="p.id" class="pcard" style="text-align:left" @click="openVariantPicker(p)">
                        <div class="pcard-main" style="cursor:pointer">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji || '👕' }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div v-if="hasProductVariants && p.variants?.length" class="pp">{{ variantPriceRange(p) }}</div>
                            <div v-else class="pp"><s v-if="p.discount_price != null" style="color:var(--dim);font-weight:500;margin-right:4px">{{ money(p.price) }}</s>{{ money(p.discount_price ?? p.price) }}</div>
                            <div v-if="hasProductVariants && p.variants?.length" class="ps">
                                <span v-if="variantStockTotal(p) <= 0" style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                                <span v-else-if="hasLowStockAlerts && stockLevel(variantStockTotal(p), p.reorder_point) === 'low'" style="color:var(--gold2);font-weight:700">⚠ {{ t('pos.inStock', { n: Math.round(variantStockTotal(p)), u: t('pos.unit') }) }} {{ t('pos.lowStock') }}</span>
                                <span v-else>{{ t('pos.pickVariant') }} ({{ p.variants.length }})</span>
                            </div>
                            <div v-else-if="p.stock <= 0" class="ps" style="color:var(--rose)">{{ t('pos.outOfStock') }}</div>
                            <div v-else-if="hasLowStockAlerts && stockLevel(p.stock, p.reorder_point) === 'low'" class="ps" style="color:var(--gold2);font-weight:700">⚠ {{ t('pos.inStock', { n: Math.round(Number(p.stock)), u: t('pos.unit') }) }} {{ t('pos.lowStock') }}</div>
                            <div v-else class="ps">{{ t('pos.inStock', { n: Math.round(Number(p.stock)), u: t('pos.unit') }) }}</div>
                        </div>
                    </button>
                </div>
                <div v-else class="empty">
                    <div class="big">👕</div>{{ t('pos.noProducts') }}
                    <div v-if="didYouMeanSuggestions.length" style="margin-top:12px">
                        <div style="font-size:12px;color:var(--dim);margin-bottom:6px">{{ t('pos.didYouMean') }}</div>
                        <button v-for="s in didYouMeanSuggestions" :key="s.id" type="button" class="btn ghost sm" style="margin:0 4px 6px" @click="q = s.name">{{ s.emoji }} {{ s.name }}</button>
                    </div>
                    <button v-if="hasPerm('stock')" type="button" class="btn ghost sm" style="margin-top:6px" @click="router.visit(route('app.stock') + '?new_name=' + encodeURIComponent(q))">{{ t('pos.addNewProductShortcut') }}</button>
                </div>
            </div>

            <!-- order panel — desktop only, a live read view of the cart with the
                 full editable Sheet one tap away for payment/discount/customer
                 details. Mirrors Pos/Index.vue's own desktop cart panel — this
                 shared screen was missing one entirely (checkout only ever
                 reachable via the top sticky bar's Sheet), the one concrete
                 desktop/web gap the vertical-by-vertical POS audit found here. -->
            <aside class="hidden lg:block lg:order-3 lg:w-80 lg:shrink-0 lg:sticky lg:top-6">
                <b style="font-size:15px;display:block;margin-bottom:10px">{{ t('pos.cartTitle') }}</b>
                <div class="card" style="padding:0;margin-bottom:14px">
                    <template v-if="cart.length">
                        <div style="display:grid;grid-template-columns:1fr auto auto;gap:8px;padding:8px 12px;font-size:10.5px;font-weight:700;color:var(--dim);text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid var(--line)">
                            <span>{{ t('pos.item') }}</span><span>{{ t('pos.qty') }}</span><span style="text-align:right">{{ t('pos.lineTotal') }}</span>
                        </div>
                        <div v-for="l in cart" :key="l.product_id + ':' + (l.product_variant_id || 'base')" style="display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;padding:9px 12px;border-bottom:1px solid var(--line);font-size:12.5px">
                            <span style="min-width:0">
                                <b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ lineProduct(l)?.name }}</b>
                                <span v-if="lineVariant(l)" style="color:var(--mut);font-size:11px">{{ variantLabel(lineVariant(l)) }}</span>
                            </span>
                            <span style="display:flex;align-items:center;gap:4px">
                                <button class="qbtn" style="width:22px;height:22px;font-size:13px" @click="decrementLine(l)">−</button>
                                <input type="number" inputmode="numeric" class="qn-input" style="font-size:12px" :value="l.qty" min="1" @change="setQty(l, $event.target.value)">
                                <button class="qbtn" style="width:22px;height:22px;font-size:13px" @click="incrementLine(l)">＋</button>
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

        <!-- variant picker: color chips, size chips per selected color -->
        <Sheet v-model="variantSheetOpen" :title="variantSheetProduct?.name">
            <template v-if="variantSheetProduct">
                <div style="font-size:13px;color:var(--mut);margin-bottom:10px">{{ t('pos.pickVariantHint') }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <button
                        v-for="v in variantSheetProduct.variants" :key="v.id" class="upill"
                        :disabled="v.stock <= 0"
                        :style="v.stock <= 0 ? 'opacity:.45' : (hasLowStockAlerts && stockLevel(v.stock, v.reorder_point) === 'low' ? 'color:var(--gold2);font-weight:700;border-color:var(--gold2)' : '')"
                        style="font-size:14px;padding:10px 16px"
                        @click="pickVariant(v)"
                    >
                        {{ variantLabel(v) }}<span v-if="v.price != null && Number(v.price) !== Number(variantSheetProduct.price)"> {{ money(v.price) }}</span>
                        {{ v.stock > 0 ? `(${v.stock})${hasLowStockAlerts && stockLevel(v.stock, v.reorder_point) === 'low' ? ' ⚠' : ''}` : `— ${t('pos.outOfStock')}` }}
                    </button>
                </div>
            </template>
        </Sheet>

        <!-- cart / checkout sheet — wide on tablet/desktop, same treatment as
             the shared Pos/Index.vue checkout -->
        <Sheet v-model="cartOpen" :title="t('pos.cart')" wide>
            <div v-if="!cart.length" class="empty"><div class="big">🧺</div>{{ t('pos.cartEmpty') }}</div>

            <div v-if="cart.length" class="checkout-cols">
            <div>
            <div v-for="l in cart" :key="l.product_id + ':' + (l.product_variant_id || 'base')" style="border-bottom:1px solid var(--line);padding:11px 0">
                <div class="cart-line" style="border:none;padding:0;align-items:flex-start">
                    <div class="nm">
                        <b>{{ lineProduct(l)?.name }}</b>
                        <button
                            v-if="lineVariant(l)" type="button"
                            style="border:none;background:none;padding:0;color:var(--sky);font-size:12.5px;font-weight:650;text-decoration:underline;text-underline-offset:2px"
                            @click="openVariantPicker(lineProduct(l), l)"
                        >{{ variantLabel(lineVariant(l)) }} · {{ t('pos.changeVariant') }}</button>
                        <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
                            <button class="btn sm ghost" style="width:32px;padding:0" @click="decrementLine(l)">−</button>
                            <b>{{ l.qty }}</b>
                            <button class="btn sm ghost" style="width:32px;padding:0" @click="incrementLine(l)">+</button>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <b>{{ money(lineTotal(l)) }}</b>
                        <div><button class="btn sm rose" style="margin-top:6px" @click="removeLine(l)">✕</button></div>
                    </div>
                </div>
                <!-- per-item discount, stacks on top of the overall discount
                     field below — same pattern as the shared Pos/Index.vue -->
                <div class="cart-line-discount">
                    <span>{{ t('pos.itemDiscount') }}</span>
                    <input v-model.number="l.discount" type="number" inputmode="numeric" placeholder="0" min="0">
                </div>
            </div>

                <div class="field">
                    <label>{{ t('pos.mobileLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.mobileHint') }}</span></label>
                    <input v-model="customerPhone" inputmode="tel" placeholder="01XXXXXXXXX">
                    <div v-if="customerLookup?.found" style="font-size:12px;color:var(--green);margin-top:5px;font-weight:600">
                        ✅ {{ t('pos.oldCustomer') }} {{ customerLookup.name }}<span v-if="customerLookup.due > 0"> {{ t('pos.dueExists', { due: money(customerLookup.due) }) }}</span>
                    </div>
                </div>
                <div class="field">
                    <label>{{ t('pos.customerName') }}</label>
                    <input v-model="customerName" :placeholder="t('pos.customerName')" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                </div>

                <div class="field">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <label style="margin-bottom:0">{{ t('pos.payment') }}</label>
                        <button type="button" style="border:none;background:none;color:var(--sky);font-size:12px;font-weight:700;padding:4px 0" @click="toggleSplitMode()">
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

                    <!-- Same real bug as Pos/Index.vue (see its own comment
                         here) — this was a bare v-else, rendering whenever
                         NOT complimentary regardless of splitMode, letting a
                         cashier type a partial amount that buildPayments()
                         silently ignores in single-method mode (it always
                         sends the full total). Gated on splitMode to match
                         what actually gets submitted. -->
                    <div v-else-if="splitMode" style="margin-top:6px">
                        <div style="display:flex;gap:8px;margin-bottom:6px">
                            <div style="flex:1"><label style="font-size:12px">{{ t('pay.cash') }}</label><input v-model.number="splitAmounts.cash" type="number" inputmode="numeric" placeholder="0" min="0" @input="onSplitCashInput"></div>
                            <div style="flex:1"><label style="font-size:12px">{{ t('pay.bkash') }}</label><input v-model.number="splitAmounts.bkash" type="number" inputmode="numeric" placeholder="0" min="0"></div>
                            <div style="flex:1"><label style="font-size:12px">{{ t('pay.nagad') }}</label><input v-model.number="splitAmounts.nagad" type="number" inputmode="numeric" placeholder="0" min="0"></div>
                        </div>
                        <div v-if="splitRemainder > 0" style="font-size:13px;font-weight:700;color:var(--rose)">
                            {{ t('pos.splitDue') }}: {{ money(splitRemainder) }}
                            <div v-if="!customerPhone.trim()" style="font-size:12px;font-weight:600;margin-top:2px">{{ t('pos.splitNeedsPhone') }}</div>
                        </div>
                    </div>

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
            </div>

            <div>
                <div style="border-top:1px solid var(--line);margin-top:10px;padding-top:10px">
                    <div style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.subtotalLabel') }}</span><b>{{ money(grossSubtotal) }}</b></div>
                    <div v-if="totalDiscount > 0" style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.overallDiscount') }}</span><b>− {{ money(totalDiscount) }}</b></div>
                    <div v-if="serviceCharge > 0" style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(serviceCharge) }}</b></div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0 0;font-weight:800;font-size:16px"><span>{{ t('pos.totalLabel') }}</span><b>{{ money(total) }}</b></div>
                </div>

                <div v-if="errorMsg" style="color:var(--rose);font-size:13px;margin-top:10px;font-weight:600">{{ errorMsg }}</div>

                <button class="btn" style="margin-top:14px" :disabled="submitting || (splitMode && splitRemainder > 0 && !customerPhone.trim())" @click="submitCheckout">
                    {{ submitting ? t('pos.processing') : `${t('pos.checkoutButton')} ${money(total)}` }}
                </button>
            </div>
            </div>
        </Sheet>

        <!-- barcode scanner -->
        <Sheet v-model="scannerOpen" :title="t('pos.scanTitle')">
            <div id="clothing-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
            <div style="text-align:center;margin-top:10px;font-size:13px;color:var(--mut)">{{ scanHint }}</div>
            <button class="btn ghost" style="margin-top:10px" @click="closeScanner">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- receipt -->
        <Sheet v-model="receiptOpen">
            <div class="no-print" style="text-align:center;margin-bottom:6px"><span style="font-size:34px">🎉</span></div>
            <div class="no-print shttl" style="text-align:center">{{ t('pos.billComplete') }}</div>
            <div v-if="lastSale" class="no-print shsub" style="text-align:center">{{ lastSale.invoice_no }} • {{ money(lastSale.total) }}</div>

            <div v-if="lastSale" id="printable-memo" :class="paperClass">
                <ReceiptMemo :shop="shop" :sale="lastSale" :platform-logo-url="platformLogoUrl" id-suffix="-clothing" />
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
/* identical to Pos/Index.vue's own print rules — #printable-memo lives
   inside <Sheet>'s <Teleport>, so this page needs its own copy rather than
   inheriting the other page's <style> block, which never mounts here. */
@page thermal58 { size: 58mm auto; margin: 0; }
@page thermal80 { size: 80mm auto; margin: 0; }
@page a4memo { size: A4; margin: 10mm; }

@media print {
    #printable-memo.paper-58 { page: thermal58; width: 58mm; }
    #printable-memo.paper-80 { page: thermal80; width: 80mm; }
    #printable-memo.paper-a4 { page: a4memo; width: 180mm; }
    #printable-memo .receipt-memo { width: auto !important; margin: 0 !important; border: none !important; }
    #app { display: none !important; }
    #sheet {
        position: static; max-height: none; overflow: visible;
        width: auto; max-width: none; padding: 0; border: 0; border-radius: 0;
        left: auto; transform: none;
    }
    #scrim { display: none !important; }
    #printable-memo {
        padding: 2mm 3mm;
        border-radius: 0; font-size: 11px;
    }
    #printable-memo h3 { font-size: 13px; }
}
</style>
