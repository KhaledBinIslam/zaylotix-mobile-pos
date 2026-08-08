<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';

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
    salesMode: String,
    activeGatewayProviders: { type: Array, default: () => [] },
});

const page = usePage();
const shop = computed(() => page.props.shop);
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
const features = computed(() => page.props.features || []);
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const hasProductVariants = computed(() => features.value.includes('product_variants'));
const hasLowStockAlerts = computed(() => features.value.includes('low_stock_alerts'));
const { toast } = useToast();
const { t } = useI18n();
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

const filtered = computed(() => props.products.filter((p) =>
    (!q.value
        || p.name.toLowerCase().includes(q.value.toLowerCase())
        || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
        || (p.barcode || '').includes(q.value)
    ) &&
    (cat.value === 'all' || p.category_id === cat.value)
));

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
function lineUnitPrice(l) {
    const v = lineVariant(l);
    if (v) return Number(v.price ?? lineProduct(l)?.price ?? 0);
    return Number(lineProduct(l)?.price ?? 0);
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

const cartCount = computed(() => cart.value.reduce((s, l) => s + l.qty, 0));
const subtotal = computed(() => cart.value.reduce((s, l) => s + lineTotal(l), 0));
// complimentary forces the server to charge nothing (discount = subtotal,
// see PosController::performCheckout) — the displayed total must reflect
// that the instant the owner picks "🎁 Complimentary", not just after the
// receipt comes back, or the sheet keeps showing a price that's never
// actually charged
const effectiveDiscount = computed(() => (payMode.value === 'complimentary' ? subtotal.value : (discount.value || 0)));
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

function resetCartAfterCheckout() {
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
    submitting.value = true;
    errorMsg.value = '';

    try {
        const res = await fetch(route('app.pos.checkout'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify({
                items: cart.value.map((l) => ({ product_id: l.product_id, product_variant_id: l.product_variant_id, qty: l.qty, discount: l.discount || 0 })),
                discount: discount.value || 0,
                complimentary: payMode.value === 'complimentary',
                payments: buildPayments(),
                customer_phone: customerPhone.value,
                customer_name: customerName.value,
            }),
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
        // fetch() itself only ever throws a TypeError for a genuine
        // connectivity failure (DNS/refused/offline) — anything else here
        // (a JSON parse failure on a non-JSON response, a bug in the code
        // above) is NOT actually a network problem, and mislabeling it as
        // one hides the real cause. Logging the real error means the next
        // report of this message comes with an actual reason attached.
        console.error('Checkout failed:', e);
        errorMsg.value = e instanceof TypeError
            ? t('pos.networkError')
            : `${t('pos.checkoutError')} (${e?.message || e})`;
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
        const res = await fetch(route('app.gatewayCheckout.initiate', provider), {
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
            { fps: 12, qrbox: { width: 260, height: 200 } },
            (text) => handleBarcode(text, (msg) => (scanHint.value = msg)),
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
        const res = await fetch(route('app.pos.barcode', code), { headers: { Accept: 'application/json' } });
        const data = await res.json();
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
watch(lastSale, async (sale) => {
    if (!sale) return;
    await nextTick();
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById('clothing-receipt-barcode-svg');
    if (el) {
        try { JsBarcode(el, 'INV-' + sale.id, { format: 'CODE128', width: 1.3, height: 30, fontSize: 9, margin: 2 }); } catch (e) { /* numeric id, shouldn't happen */ }
    }
    const QRCode = (await import('qrcode')).default;
    const qrEl = document.getElementById('clothing-receipt-rating-qr');
    if (qrEl) {
        try { await QRCode.toCanvas(qrEl, window.location.origin + route('rate.show', sale.id), { width: 90, margin: 1 }); } catch (e) { /* non-critical */ }
    }
}, { flush: 'post' });

function sendMemoWA() {
    const s = lastSale.value;
    if (!s) return;
    const phone = s.customer?.phone || '';
    const num = phone ? '88' + phone.replace(/\D/g, '').replace(/^88/, '') : '';
    const lines = s.items.map((l) => `${l.product_name}${l.variant_label ? ' (' + l.variant_label + ')' : ''}  ${l.qty}×${l.price} = ${money(l.price * l.qty)}`).join('\n');
    const shopName = shop.value?.name || 'Zaylotix POS';
    const text = `*${shopName}*\n${'─'.repeat(16)}\nমেমো: ${s.invoice_no}\n${'─'.repeat(16)}\n${lines}\n${'─'.repeat(16)}\n*মোট: ${money(s.total)}*\n\nধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
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
            <button type="button" class="btn" style="box-shadow:0 6px 18px rgba(242,106,27,.4)" @click="cartOpen = true">
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
                    <input v-model="q" :placeholder="t('pos.searchPlaceholder')" style="flex:1">
                    <button v-if="canScan" class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openScanner">📷</button>
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
                            <div class="pp">{{ money(p.price) }}</div>
                            <div v-if="hasProductVariants && p.variants?.length" class="ps">{{ t('pos.pickVariant') }} ({{ p.variants.length }})</div>
                            <div v-else-if="p.stock <= 0" class="ps" style="color:var(--rose)">{{ t('pos.outOfStock') }}</div>
                            <div v-else-if="hasLowStockAlerts && stockLevel(p.stock, p.reorder_point) === 'low'" class="ps" style="color:var(--gold2);font-weight:700">⚠ {{ t('pos.inStock', { n: p.stock }) }} {{ t('pos.lowStock') }}</div>
                        </div>
                    </button>
                </div>
                <div v-else class="empty"><div class="big">👕</div>{{ t('pos.noProducts') }}</div>
            </div>
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
                        {{ variantLabel(v) }}<span v-if="Number(v.price) !== Number(variantSheetProduct.price)"> {{ money(v.price) }}</span>
                        {{ v.stock > 0 ? `(${v.stock})${hasLowStockAlerts && stockLevel(v.stock, v.reorder_point) === 'low' ? ' ⚠' : ''}` : `— ${t('pos.outOfStock')}` }}
                    </button>
                </div>
            </template>
        </Sheet>

        <!-- cart / checkout sheet -->
        <Sheet v-model="cartOpen" :title="t('pos.cart')">
            <div v-if="!cart.length" class="empty"><div class="big">🧺</div>{{ t('pos.cartEmpty') }}</div>

            <div v-for="l in cart" :key="l.product_id + ':' + (l.product_variant_id || 'base')" class="cart-line" style="align-items:flex-start">
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

            <template v-if="cart.length">
                <div class="field">
                    <label>{{ t('pos.mobileLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.mobileHint') }}</span></label>
                    <input v-model="customerPhone" inputmode="tel" placeholder="01XXXXXXXXX">
                    <div v-if="customerLookup?.found" style="font-size:12px;color:var(--green);margin-top:5px;font-weight:600">
                        ✅ {{ t('pos.oldCustomer') }} {{ customerLookup.name }}<span v-if="customerLookup.due > 0"> {{ t('pos.dueExists', { due: money(customerLookup.due) }) }}</span>
                    </div>
                </div>
                <div class="field">
                    <label>{{ t('pos.customerName') }}</label>
                    <input v-model="customerName" :placeholder="t('pos.customerName')">
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
                            <div style="flex:1"><label style="font-size:12px">{{ t('pay.cash') }}</label><input v-model.number="splitAmounts.cash" type="number" inputmode="numeric" placeholder="0" min="0"></div>
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

                <div style="border-top:1px solid var(--line);margin-top:10px;padding-top:10px">
                    <div style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.subtotalLabel') }}</span><b>{{ money(subtotal) }}</b></div>
                    <div v-if="effectiveDiscount > 0" style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.overallDiscount') }}</span><b>− {{ money(effectiveDiscount) }}</b></div>
                    <div v-if="serviceCharge > 0" style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('pos.serviceCharge') }}</span><b>+ {{ money(serviceCharge) }}</b></div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0 0;font-weight:800;font-size:16px"><span>{{ t('pos.totalLabel') }}</span><b>{{ money(total) }}</b></div>
                </div>

                <div v-if="errorMsg" style="color:var(--rose);font-size:13px;margin-top:10px;font-weight:600">{{ errorMsg }}</div>

                <button class="btn" style="margin-top:14px" :disabled="submitting || (splitMode && splitRemainder > 0 && !customerPhone.trim())" @click="submitCheckout">
                    {{ submitting ? t('pos.processing') : `${t('pos.checkoutButton')} ${money(total)}` }}
                </button>
            </template>
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
                <div v-if="lastSale.discount > 0" class="rc-l"><span>ওভারঅল ছাড়</span><b>− {{ money(lastSale.discount) }}</b></div>
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
                <div class="rc-f">{{ shop?.receipt_footer || 'ধন্যবাদ! আবার আসবেন 🙏' }}</div>
                <div style="display:flex;justify-content:center;margin-top:8px"><svg id="clothing-receipt-barcode-svg"></svg></div>
                <div style="display:flex;flex-direction:column;align-items:center;margin-top:6px;gap:2px">
                    <canvas id="clothing-receipt-rating-qr"></canvas>
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
/* identical to Pos/Index.vue's own print rules — #printable-memo lives
   inside <Sheet>'s <Teleport>, so this page needs its own copy rather than
   inheriting the other page's <style> block, which never mounts here. */
@media print {
    @page { size: 58mm auto; margin: 0; }
    #app { display: none !important; }
    #sheet {
        position: static; max-height: none; overflow: visible;
        width: auto; max-width: none; padding: 0; border: 0; border-radius: 0;
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
