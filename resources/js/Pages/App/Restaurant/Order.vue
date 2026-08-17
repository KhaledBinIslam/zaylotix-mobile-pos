<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useOfflineActionQueue } from '@/composables/useOfflineActionQueue';
import { usePollingReload } from '@/composables/usePollingReload';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';

const props = defineProps({
    order: Object, products: Array, categories: Array,
    otherOccupiedTables: { type: Array, default: () => [] },
    freeTables: { type: Array, default: () => [] },
});
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const page = usePage();
const shop = computed(() => page.props.shop);
// order.total (the server-computed field) only refreshes on a real reload —
// recomputing it here from order.items instead means (a) it's usable in
// <script setup> code, where props aren't auto-unwrapped as bare names the
// way they are inside <template>, and (b) an item added while offline (see
// postItem()'s optimistic push to props.order.items) shows up in the
// subtotal/VAT-preview immediately instead of looking stale until sync.
const liveOrderTotal = computed(() => props.order.items
    .reduce((s, it) => s + it.price * it.qty - Math.min(it.discount || 0, it.price * it.qty), 0));
// same "in cart" badge/highlight Pos/Index.vue's product grid already shows
// (see qtyInCart there) — this screen never had it, so a menu item that was
// genuinely added gave no lasting visual sign of it on the card itself,
// only a change to the order panel a cashier might not be looking at
function qtyInOrder(productId) {
    return props.order.items.filter((it) => it.product_id === productId).reduce((s, it) => s + it.qty, 0);
}
// complimentary (free/staff-meal) bills give away real inventory for free —
// owner-only, same reasoning as Pos/Index.vue
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
// a takeaway/parcel order has no restaurant_table_id at all (see
// RestaurantTableController::openTakeaway), so table_name is null — same for
// a food-first dine-in order that hasn't been seated yet (see openOrder()),
// which needs its own distinct fallback rather than being mislabeled "takeaway"
const displayName = computed(() => {
    if (props.order.table_name) return props.order.table_name;
    if (props.order.order_source === 'delivery') return t('restaurant.sourceDelivery');
    if (props.order.order_source === 'takeaway') return t('restaurant.takeawayLabel');
    return t('restaurant.newOrder');
});
// preview only, mirrors TableOrderController::bill()'s exact formula — the
// server remains authoritative at billing time, this just shows the
// cashier roughly what to expect while still building the order
const vatPreview = computed(() => {
    if (!shop.value) return 0;
    if (shop.value.vat_mode === 'full') {
        const rate = Number(shop.value.vat_rate) || 0;
        return Math.round(liveOrderTotal.value * rate / (100 + rate) * 100) / 100;
    }
    if (shop.value.vat_mode === 'turnover') return Math.round(liveOrderTotal.value * (Number(shop.value.turnover_rate) || 0) / 100 * 100) / 100;
    return 0;
});

const q = ref('');
const cat = ref('all');
const filtered = computed(() => props.products.filter((p) =>
    (!q.value
        || p.name.toLowerCase().includes(q.value.toLowerCase())
        || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
        || (p.barcode || '').includes(q.value)
    ) &&
    (cat.value === 'all' || p.category_id === cat.value)
));

// --- barcode scan (camera + hardware) — this screen had NEITHER before,
// the one concrete gap the vertical-by-vertical POS audit found for
// Restaurant: a shop that also stocks bottled drinks/snacks alongside
// cooked food had no way to scan them onto a table order, only tap the
// grid. Same mechanism Pos/Index.vue and Clothing/Pos.vue already use
// (html5-qrcode + a hardware keyboard-wedge listener), simplified here
// since a table order has no weight/pack-size/variant lines to route to —
// TableOrderController::addItem() flatly rejects a variant product, so a
// scanned one is just reported back rather than posted and rejected. ---
const { toast } = useToast();
// scope, deliberately: only addItem/incItem (building the order) and
// submitBill (completing the sale) are queued offline below — the two
// money/stock-critical actions Khaled explicitly asked for. Lower-stakes
// mutations (decrement/remove/toggleServed/updateMeta) stay Inertia-based,
// best-effort with the global network-failure toast (app.js) as feedback;
// queueing every mutation type here would be a much larger, riskier scope
// than this pass covers, and none of them risk silently losing money/stock
// the way a dropped add-item or bill would.
const offlineActions = useOfflineActionQueue();
const canScan = computed(() => shop.value?.sales_mode === 'scan' || shop.value?.sales_mode === 'both');
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
            (decodedText) => handleBarcode(decodedText, (msg) => (scanHint.value = msg)),
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

async function handleBarcode(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return; // debounce repeat frames
    lastScanCode = decodedText;
    lastScanAt = now;

    if (navigator.vibrate) navigator.vibrate(40);

    const code = decodedText.trim();
    const product = props.products.find((p) => p.barcode === code);

    if (product) {
        if (product.variants_count > 0) {
            onFeedback('❌ ' + product.name + ' — ' + t('restaurant.scanVariantNotSupported'));
            return;
        }
        addItem(product);
        onFeedback('✅ ' + product.name);
    } else {
        // not in the locally loaded list (added by another device since page load) — ask the server
        try {
            const res = await fetch(route('app.pos.barcode', code), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (data.found && !data.product.variants?.length) {
                addItem(data.product);
                onFeedback('✅ ' + data.product.name);
            } else if (data.found) {
                onFeedback('❌ ' + data.product.name + ' — ' + t('restaurant.scanVariantNotSupported'));
            } else {
                onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
            }
        } catch (e) {
            onFeedback('❌ ' + t('pos.productNotFound') + ' ' + decodedText);
        }
    }
}

const hardwareScannerEnabled = computed(() => shop.value?.hardware_scanner_enabled ?? true);
useHardwareScanner((code) => handleBarcode(code, (msg) => toast(msg)), hardwareScannerEnabled);

// --- pending vs served (separate from kot_printed_at — a kitchen can be
// mid-prep on something already sent but not yet brought to the table) ---
const pendingItems = computed(() => props.order.items.filter((it) => !it.served_at));
const servedItems = computed(() => props.order.items.filter((it) => it.served_at));
function toggleServed(item) {
    router.post(route('app.restaurant.orderItems.toggleServed', item.id), {}, { preserveScroll: true });
}

// --- order source (dine-in/takeaway/3rd-party delivery) + kitchen note ---
const DELIVERY_PLATFORMS = ['Food Panda', 'Pathao Food', 'HungryNaki', 'Chaldal', 'অন্য কিছু'];
const metaForm = useForm({
    order_source: props.order.order_source || 'dine_in',
    delivery_platform: props.order.delivery_platform || '',
    kitchen_note: props.order.kitchen_note || '',
    waiter_name: props.order.waiter_name || '',
});
const metaChanged = computed(() =>
    metaForm.order_source !== (props.order.order_source || 'dine_in') ||
    metaForm.delivery_platform !== (props.order.delivery_platform || '') ||
    metaForm.kitchen_note !== (props.order.kitchen_note || '') ||
    metaForm.waiter_name !== (props.order.waiter_name || '')
);
function saveMeta() {
    metaForm.patch(route('app.restaurant.orders.meta', props.order.id), { preserveScroll: true });
}

// food-first flow: a dine-in order started via "New Order" has no table
// yet (props.order.table_id is null) — this seats it once the cashier is
// ready, from right here on the order screen, instead of requiring the
// table to be picked before any food could be added
const tableToAssign = ref('');
const assignTableForm = useForm({});
function assignTable() {
    if (!tableToAssign.value) return;
    assignTableForm.transform(() => ({ restaurant_table_id: tableToAssign.value }))
        .patch(route('app.restaurant.orders.assignTable', props.order.id), { preserveScroll: true });
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/**
 * Raw fetch instead of router.post — needed so a genuine connectivity
 * failure can be told apart from a real server rejection and queued
 * offline (see useOfflineActionQueue), the same distinction Pos/Index.vue's
 * buildCheckoutPayload/submitCheckout already draws. A queued add-item
 * optimistically appends to props.order.items client-side (with a
 * temporary negative id, since the server doesn't have this row yet) so
 * the cashier keeps seeing what they rang up instead of the screen
 * reverting to "not added" — replaced by the server's real state once
 * this order's queue actually syncs and the page reloads.
 */
async function postItem(productId, qty) {
    // url/body construction moved INSIDE the try block, deliberately — this
    // used to sit above it, so anything unexpected in route() (e.g. Ziggy
    // throwing if props.order.id were ever momentarily unset) would reject
    // this async function with nothing awaiting or catching it: a silent,
    // invisible failure indistinguishable from the click not registering at
    // all, with no toast, no console-visible symptom a cashier would ever
    // notice — exactly the class of bug the stock<=0 guard turned out to be
    // earlier. Now every path through this function ends in a toast.
    let url, body;
    try {
        url = route('app.restaurant.orders.items.store', props.order.id);
        body = { product_id: productId, qty };
        // fetchWithSessionRetry: one safe, automatic retry on a 401 (see its
        // own docblock) — the same transient-hiccup protection as the
        // background polls above, for this explicit tap too.
        const res = await fetchWithSessionRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        if (res.ok) {
            // the one path that used to end with no feedback at all, despite
            // this function's own docblock above claiming otherwise — a
            // click that actually worked (item genuinely reached the order,
            // confirmed live against the real dev DB) still looked and felt
            // exactly like a click that did nothing, because nothing on
            // screen said so until the cashier noticed the cart total change
            // themselves. Matches Pos/Index.vue's addToCart() toast.
            const product = props.products.find((p) => p.id === productId);
            toast('✅ ' + (product?.name || '') + ' ' + t('pos.added'));
            // Root-cause fix: the add really did save (the POST above got a
            // 200) but the cart panel used to stay looking empty/unchanged
            // whenever this FOLLOW-UP router.reload() failed — a second,
            // separate request that can transiently fail on its own (shared-
            // host session hiccups under load) even when the add itself
            // succeeded. A cashier saw a clear "✅ added" toast right next to
            // a cart that never moved, with the item genuinely sitting in
            // the database the whole time. Mirrors the offline-queue branch
            // below exactly: update order.items directly, right here, so the
            // cart is correct the instant the add succeeds — the reload
            // below is now just a best-effort reconciliation (real id,
            // served_at, another device's changes), never the only path to
            // a correct-looking cart.
            const existing = props.order.items.find((it) => it.product_id === productId && !it.kot_printed_at && !it.sale_id);
            if (existing) {
                existing.qty += qty;
            } else if (product) {
                props.order.items.push({
                    id: -Date.now(), product_id: productId, product_name: product.name,
                    qty, price: product.price, cost: product.cost, discount: 0,
                    kot_printed_at: null, served_at: null, sale_id: null,
                });
            }
            // A one-time retry costs nothing and resolves the transient
            // network blips this is realistically ever caused by. If BOTH
            // attempts fail, the cart above is still correct (the optimistic
            // update covers it) — this second failure just means the
            // server's exact copy (real id, any other device's changes)
            // hasn't been fetched yet, worth a quiet heads-up rather than
            // silence, but not a "your sale is wrong" alarm. pollReload (not
            // router.reload directly) so a transient auth hiccup on either
            // attempt answers with a catchable error instead of a redirect
            // that would drag the cashier away mid-sale — see usePollingReload.
            pollReload({
                only: ['order'], preserveScroll: true, preserveState: true,
                onError: () => pollReload({
                    only: ['order'], preserveScroll: true, preserveState: true,
                    onError: () => toast('⚠️ ' + t('restaurant.cartSyncFailed')),
                }),
            });
        } else {
            const data = await res.json().catch(() => ({}));
            toast('❌ ' + (data.message || t('pos.checkoutError')));
        }
    } catch (e) {
        if (!url) {
            // failed before the request was even built (route() threw, or
            // similar) — not a connectivity issue, don't queue it offline
            toast('❌ ' + t('pos.checkoutError'));
        } else if (!navigator.onLine || e instanceof TypeError) {
            await offlineActions.queueAction(url, 'POST', body);
            const product = props.products.find((p) => p.id === productId);
            const existing = props.order.items.find((it) => it.product_id === productId && !it.kot_printed_at && !it.sale_id);
            if (existing) {
                existing.qty += qty;
            } else if (product) {
                props.order.items.push({
                    id: -Date.now(), product_id: productId, product_name: product.name,
                    qty, price: product.price, cost: product.cost, discount: 0,
                    kot_printed_at: null, served_at: null, sale_id: null,
                });
            }
            toast(t('pos.savedOffline'));
        } else {
            toast('❌ ' + t('pos.networkError'));
        }
    }
}
/**
 * Bug fix: this used to silently do nothing at all when p.stock <= 0 —
 * meaning a click on ANY menu item seeded/left at its default stock of 0
 * (see DemoShopSeeder's restaurantDemo(), and any product an owner adds
 * without bothering to set a stock number) added nothing to the order and
 * gave zero feedback, while a product that happened to have real stock
 * worked fine. TableOrderController::addItem() already validates stock and
 * returns a real, visible error via postItem()'s toast when it's actually
 * insufficient — that's the right place for this check, not a silent
 * client-side no-op with no error shown.
 */
function addItem(p) {
    postItem(p.id, 1);
}
function incItem(item) {
    postItem(item.product_id, 1);
}
function decItem(item) {
    router.patch(route('app.restaurant.orderItems.decrement', item.id), {}, { preserveScroll: true });
}
function removeItem(item) {
    router.delete(route('app.restaurant.orderItems.destroy', item.id), { preserveScroll: true });
}
// per-item discount, saved on blur (not every keystroke) — stacks with the
// whole-bill discount at bill() time, same as the shared POS's per-line discount
function saveItemDiscount(item) {
    router.patch(route('app.restaurant.orderItems.discount', item.id), { discount: item.discount || 0 }, { preserveScroll: true });
}

function printKot() {
    // saves whatever's typed in the note/order-type fields even if the
    // cashier never tapped the separate "Save" button below — the printed
    // ticket itself already reads live from metaForm (see #printable-kot),
    // this just makes sure the server/report data doesn't stay stale too
    if (metaChanged.value) saveMeta();

    // window.print() must fire synchronously, inside this click handler —
    // the previous version waited on the network POST to finish and then a
    // setTimeout before calling it, which loses the "direct user gesture"
    // window print() needs on most mobile browsers and silently does
    // nothing. This was the actual cause of "KOT print hocchena". The
    // ticket's content (items/note/order type) is all already on this page,
    // so there's nothing worth waiting on the server for.
    window.print();

    router.post(route('app.restaurant.orders.kot', props.order.id), {}, { preserveScroll: true });
}

// one-click combo: sends whatever hasn't reached the kitchen yet (same
// action as the "Print KOT" button) and, without a second tap, opens the
// bill sheet right after — for a cashier who wants both done together
// instead of pressing two separate buttons in sequence
function billAndPrintKot() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    // bill sheet opens FIRST, deliberately — window.print() inside
    // printKot() blocks script execution on most desktop browsers until the
    // print dialog is dismissed (and on some mobile browsers with no print
    // handler configured, it can silently never return at all), so a
    // billSheet.value = true placed AFTER it either waits on the cashier
    // closing that dialog or never runs — exactly the reported "KOT+Bill
    // একসাথে দিলে bill screen আসছে না" bug. Nothing about what actually
    // gets printed changes: #printable-kot's own @media print rule already
    // hides the rest of the page (including this sheet) regardless of
    // what's open on screen.
    billSheet.value = true;
    printKot();
}

// same one-click idea, but sends the kitchen ticket over WhatsApp instead of
// printing it — for a kitchen that reads orders off a phone, not a printer
function billAndSendKotWA() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    billSheet.value = true;
    sendKotWA();
}

// UI-only preference (see the migration's comment) — reorders/relabels the
// kitchen-send vs bill-now actions below, never blocks either one
const payFirst = computed(() => shop.value?.payment_timing === 'pay_first');

const kitchenWaNumber = computed(() => shop.value?.kitchen_whatsapp || '');
function sendKotWA() {
    if (metaChanged.value) saveMeta();

    const num = '88' + kitchenWaNumber.value.replace(/\D/g, '').replace(/^88/, '');
    const lines = props.order.items.map((it) => `${it.product_name}  ×${it.qty}`).join('\n');
    // reads the live form values, not the (possibly still-unsaved) server
    // props — same reasoning as printKot(), so an unsaved note/order-type
    // edit still makes it into the message sent right now
    const sourceLine = metaForm.order_source === 'delivery'
        ? `\n🛵 ${t('restaurant.sourceDelivery')} — ${metaForm.delivery_platform || ''}`
        : metaForm.order_source === 'takeaway' ? `\n🥡 ${t('restaurant.sourceTakeaway')}` : '';
    const noteLine = metaForm.kitchen_note ? `\n📝 ${metaForm.kitchen_note}` : '';
    const text = `*${t('restaurant.kotTitle')}*\n${'─'.repeat(16)}\n${displayName.value}${sourceLine}\n${'─'.repeat(16)}\n${lines}${noteLine}`;
    // opened synchronously, in direct response to the click — like
    // window.print() in printKot(), waiting on a network round-trip first
    // risks the browser treating this as an unrequested popup and blocking it
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');

    // mark as sent to kitchen too, same as the print button — WhatsApp is
    // just an alternate delivery channel for the same "sent to kitchen" event
    router.post(route('app.restaurant.orders.kot', props.order.id), {}, { preserveScroll: true });
}

const cancelOrder = () => {
    if (!confirm(t('restaurant.cancelOrderConfirm'))) return;
    router.post(route('app.restaurant.orders.cancel', props.order.id));
};

// --- split bill — off by default (bills everything, exactly like before);
// toggling it on shows a checkbox per line and only sends those item_ids ---
const splitMode = ref(false);
const selectedItemIds = ref([]);
function toggleSplitMode() {
    splitMode.value = !splitMode.value;
    selectedItemIds.value = [];
}
function toggleItemSelected(itemId) {
    const i = selectedItemIds.value.indexOf(itemId);
    if (i === -1) selectedItemIds.value.push(itemId);
    else selectedItemIds.value.splice(i, 1);
}
const splitSubtotal = computed(() => props.order.items
    .filter((it) => selectedItemIds.value.includes(it.id))
    .reduce((s, it) => s + it.price * it.qty - Math.min(it.discount || 0, it.price * it.qty), 0));

// --- billing sheet ---
const billSheet = ref(false);
const payMode = ref('cash');
const discount = ref(0);
const customerPhone = ref('');
const customerName = ref('');
const billBaseTotal = computed(() => (splitMode.value ? splitSubtotal.value : liveOrderTotal.value));
// complimentary forces the server to charge nothing (discount = subtotal,
// see TableOrderController::bill()) — the displayed total must reflect that
// the instant "🎁 Complimentary" is picked, not just after the bill is sent
const effectiveDiscount = computed(() => (payMode.value === 'complimentary' ? billBaseTotal.value : (discount.value || 0)));
// mirrors TableOrderController::bill()'s own math — additive on top of the
// discounted subtotal, unlike the VAT preview above which is backed out of
// an already-inclusive price and never added to what's actually charged
const serviceCharge = computed(() => {
    const rate = shop.value?.service_charge_rate;
    if (rate === null || rate === undefined) return 0;
    return Math.round(Math.max(0, billBaseTotal.value - effectiveDiscount.value) * Number(rate) / 100 * 100) / 100;
});
const billTotal = computed(() => Math.max(0, billBaseTotal.value - effectiveDiscount.value) + serviceCharge.value);
const billSubmitting = ref(false);
function openBillSheet() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    billSheet.value = true;
}
/**
 * Raw fetch, same reasoning as postItem() above — needed to tell a real
 * connectivity failure apart from a genuine rejection and queue it. Unlike
 * addItem (which optimistically shows the item was added), a queued bill
 * can't optimistically redirect to a printable receipt — there's no Sale
 * row, no invoice number, until this actually reaches the server. The
 * order just stays open/visible here; once this device is back online and
 * the queue syncs, TableOrderController::bill() runs for real (same
 * locking/re-validation as an online bill), and the cashier can print from
 * the sale it creates then, same as any other completed order.
 *
 * The Accept:application/json header below makes bill() take its
 * wantsJson() branch and return {sale_id} directly instead of a redirect —
 * so on success we can navigate straight to the receipt via Inertia,
 * exactly like the normal online path ends up at, rather than reloading
 * this now-empty order page.
 */
async function submitBill() {
    if (billSubmitting.value) return;
    billSubmitting.value = true;
    const url = route('app.restaurant.orders.bill', props.order.id);
    const body = {
        discount: discount.value || 0,
        complimentary: payMode.value === 'complimentary',
        // a discount that fully covers the total legitimately zeroes it —
        // see Pos/Index.vue's buildPayments() for why a real tender must
        // not be sent when there's nothing left to actually collect
        payments: (payMode.value === 'credit' || payMode.value === 'complimentary' || billTotal.value <= 0) ? [] : [{ method: payMode.value, amount: billTotal.value }],
        customer_phone: customerPhone.value,
        customer_name: customerName.value,
        item_ids: splitMode.value ? selectedItemIds.value : undefined,
    };

    try {
        // fetchWithSessionRetry: one safe, automatic retry on a 401 — a
        // 401 here happens in middleware, strictly before bill() starts its
        // transaction, so nothing was ever charged the first time.
        const res = await fetchWithSessionRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        if (res.ok) {
            const data = await res.json().catch(() => ({}));
            splitMode.value = false;
            selectedItemIds.value = [];
            if (data.sale_id) {
                router.visit(route('app.sales.show', { sale: data.sale_id, autoprint: 1 }));
            } else {
                router.visit(url.replace('/bill', '')); // shouldn't happen — fall back to reloading this order
            }
        } else {
            const data = await res.json().catch(() => ({}));
            toast('❌ ' + (data.message || t('pos.checkoutError')));
        }
    } catch (e) {
        if (!navigator.onLine || e instanceof TypeError) {
            await offlineActions.queueAction(url, 'POST', body);
            billSheet.value = false;
            toast(t('pos.savedOffline'));
        } else {
            toast('❌ ' + t('pos.networkError'));
        }
    } finally {
        billSubmitting.value = false;
    }
}

// --- table transfer / merge ---
const tableActionSheet = ref(false);
const tableActionMode = ref('transfer'); // 'transfer' this order away, or 'merge' another table's order into this one
const tableActionTargetId = ref('');
function submitTableAction() {
    if (!tableActionTargetId.value) return;
    if (tableActionMode.value === 'transfer') {
        router.post(route('app.restaurant.tables.transfer', props.order.table_id), { to_table_id: tableActionTargetId.value }, {
            onSuccess: () => (tableActionSheet.value = false),
        });
    } else {
        router.post(route('app.restaurant.tables.merge', props.order.table_id), { from_table_id: tableActionTargetId.value }, {
            onSuccess: () => (tableActionSheet.value = false),
        });
    }
}

// transfer/merge/cancel are rare, one-off actions — tucked behind a single
// header button instead of sitting in the main scroll flow, so they're
// still reachable in one tap but never push the everyday fields further down
const moreSheet = ref(false);

// another device could add/remove items on the same table order
const { pollReload } = usePollingReload();
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (billSheet.value || scannerOpen.value) return;
        pollReload({ only: ['order'], preserveScroll: true, preserveState: true });
    }, 8000);
});
onBeforeUnmount(() => {
    clearInterval(pollTimer);
    if (html5Qrcode) html5Qrcode.stop().catch(() => {});
});
</script>

<template>
    <Head :title="displayName" />
    <AppLayout active="restaurant">
        <div style="display:flex;align-items:start;justify-content:space-between;gap:10px">
            <div>
                <div class="pgttl">{{ displayName }}</div>
                <div class="pgsub">{{ t('restaurant.orderTitle') }} • {{ money(liveOrderTotal) }}</div>
            </div>
            <button class="btn ghost sm" style="width:auto;padding:8px 14px;flex:0 0 auto" @click="moreSheet = true">⋯ {{ t('common.more') }}</button>
        </div>

        <div class="lg:flex lg:gap-6 lg:items-start">
            <!-- order/cart panel — natural first on mobile (unchanged position), becomes the sticky right column on desktop.
                 Cart items come first (what a cashier actually needs to see/manage the instant they open an order); order-
                 type/waiter/kitchen-note are set once and rarely touched again, so they sit below the bill actions, not
                 above the items — restored here after briefly moving them to the top to mirror a reference screenshot,
                 which undid an earlier, already-confirmed-good fix (see git history: cart-first was explicitly validated
                 by the user before this file mirrored a different reference's top-first layout — that reference's layout
                 wasn't actually requested to replace this one). -->
            <div class="lg:order-3 lg:w-80 lg:shrink-0 lg:sticky lg:top-6 order-panel">
                <div class="card" style="margin-bottom:14px">
                    <div v-if="order.items.length">
                        <div v-if="order.items.length > 1" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <span style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase">{{ t('restaurant.splitBillToggle') }}</span>
                            <button class="btn sm ghost" style="width:auto;padding:4px 12px" @click="toggleSplitMode">{{ splitMode ? t('common.cancel') : t('restaurant.splitBillStart') }}</button>
                        </div>
                        <div v-if="pendingItems.length" style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:4px">{{ t('restaurant.pendingItems') }}</div>
                        <div v-for="it in pendingItems" :key="it.id" style="margin-bottom:6px">
                            <div class="cart-line">
                                <input v-if="splitMode" type="checkbox" style="width:auto;margin-right:2px" :checked="selectedItemIds.includes(it.id)" @change="toggleItemSelected(it.id)">
                                <div class="nm">
                                    <b>{{ it.product_name }}</b>
                                    <span>{{ money(it.price) }} {{ t('restaurant.each') }} = {{ money(it.qty * it.price) }}<span v-if="it.discount > 0" style="color:var(--rose)"> (−{{ money(it.discount) }} {{ t('pos.itemDiscountApplied') }})</span></span>
                                    <span v-if="it.kot_printed_at" style="color:var(--green)">✓ {{ t('restaurant.sentToKitchen') }}</span>
                                </div>
                                <template v-if="!splitMode">
                                    <button class="qbtn" @click="decItem(it)">−</button>
                                    <span class="qn">{{ it.qty }}</span>
                                    <button class="qbtn" @click="incItem(it)">＋</button>
                                </template>
                            </div>
                            <div v-if="!splitMode" class="cart-line-discount">
                                <span>{{ t('pos.itemDiscount') }}</span>
                                <input v-model.number="it.discount" type="number" inputmode="numeric" placeholder="0" min="0" @blur="saveItemDiscount(it)">
                            </div>
                            <button v-if="!splitMode" class="btn sm ghost" style="width:100%" @click="toggleServed(it)">✅ {{ t('restaurant.markServed') }}</button>
                        </div>
                        <div v-if="splitMode && selectedItemIds.length" class="card" style="margin:8px 0;padding:8px 12px;background:var(--surface2, var(--card))">
                            <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700"><span>{{ t('restaurant.splitBillSelectedTotal') }}</span><span>{{ money(splitSubtotal) }}</span></div>
                        </div>

                        <div v-if="servedItems.length" style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin:10px 0 4px">{{ t('restaurant.servedItems') }}</div>
                        <div v-for="it in servedItems" :key="it.id" class="cart-line" style="opacity:.6">
                            <div class="nm">
                                <b>{{ it.product_name }}</b>
                                <span>{{ money(it.price) }} {{ t('restaurant.each') }} = {{ money(it.qty * it.price) }}</span>
                                <span style="color:var(--green)">✓ {{ t('restaurant.served') }}</span>
                            </div>
                            <button class="btn sm ghost" @click="toggleServed(it)">↩ {{ t('restaurant.undoServed') }}</button>
                        </div>
                    </div>
                    <div v-else class="empty" style="padding:20px 0"><div class="big">🍽️</div>{{ t('restaurant.noItemsYet') }}</div>
                </div>

                <!-- order type / table / kitchen note / waiter — moved above the
                     KOT-print/bill buttons per Khaled's explicit request: below
                     them, a cashier could print/bill without ever scrolling down
                     far enough to notice the note field was even there. Now it's
                     the natural next thing after building the cart, before the
                     print/bill actions. Every field here auto-saves itself (on
                     click for the order-type segment, on blur for text fields) —
                     no separate "Save" button to remember to tap anymore. -->
                <div class="card" style="margin-bottom:14px">
                    <!-- food-first: this order has no table yet — shown right above the
                         order-type picker so seating happens as part of the same "now that
                         the cart is built, decide table/takeaway/delivery" step -->
                    <div v-if="!order.table_id && metaForm.order_source === 'dine_in' && freeTables.length" class="card" style="margin-bottom:10px;background:var(--goldSoft);border-color:var(--gold2)">
                        <div style="font-size:12.5px;font-weight:700;color:var(--gold2);margin-bottom:8px">🪑 {{ t('restaurant.assignTableHint') }}</div>
                        <div style="display:flex;gap:8px">
                            <select v-model="tableToAssign" style="flex:1;margin:0">
                                <option value="">{{ t('damage.selectPlaceholder') }}</option>
                                <option v-for="tb in freeTables" :key="tb.id" :value="tb.id">{{ tb.name }}</option>
                            </select>
                            <button class="btn sm" style="width:auto;padding:0 16px" :disabled="!tableToAssign || assignTableForm.processing" @click="assignTable">{{ assignTableForm.processing ? '...' : t('restaurant.seatTable') }}</button>
                        </div>
                    </div>
                    <div v-else-if="!order.table_id && metaForm.order_source === 'dine_in'" style="font-size:12px;color:var(--dim);margin-bottom:10px">⚠️ {{ t('restaurant.tableNotAssigned') }}</div>

                    <div class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.orderSource') }}</label>
                        <div class="seg">
                            <button :class="{ on: metaForm.order_source === 'dine_in' }" @click="metaForm.order_source = 'dine_in'; saveMeta()">{{ t('restaurant.sourceDineIn') }}</button>
                            <button :class="{ on: metaForm.order_source === 'takeaway' }" @click="metaForm.order_source = 'takeaway'; saveMeta()">{{ t('restaurant.sourceTakeaway') }}</button>
                            <button :class="{ on: metaForm.order_source === 'delivery' }" @click="metaForm.order_source = 'delivery'; saveMeta()">{{ t('restaurant.sourceDelivery') }}</button>
                        </div>
                    </div>
                    <div v-if="metaForm.order_source === 'delivery'" class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.deliveryPlatform') }}</label>
                        <select v-model="metaForm.delivery_platform" @change="saveMeta">
                            <option value="">{{ t('damage.selectPlaceholder') }}</option>
                            <option v-for="p in DELIVERY_PLATFORMS" :key="p" :value="p">{{ p }}</option>
                        </select>
                        <input v-if="metaForm.delivery_platform === 'অন্য কিছু'" v-model="metaForm.delivery_platform" :placeholder="t('restaurant.deliveryPlatformCustom')" style="margin-top:6px" @blur="saveMeta">
                    </div>
                    <div class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.kitchenNote') }} <span style="color:var(--dim);font-weight:400">{{ t('restaurant.kitchenNoteHint') }}</span></label>
                        <textarea v-model="metaForm.kitchen_note" rows="2" :placeholder="t('restaurant.kitchenNotePlaceholder')" @blur="saveMeta"></textarea>
                    </div>
                    <div class="field">
                        <label>{{ t('restaurant.waiterName') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                        <input v-model="metaForm.waiter_name" :placeholder="t('restaurant.waiterNamePlaceholder')" @blur="saveMeta">
                    </div>
                </div>

                <!-- sticky footer — subtotal + total + bill/kitchen actions stay on
                     screen while the cart above scrolls, so the total and the
                     buttons a cashier needs most are never scrolled out of view,
                     the same way on mobile, tablet and desktop -->
                <div class="sticky-footer">
                    <div v-if="order.items.length" class="card" style="margin-bottom:8px;padding:10px 14px">
                        <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding:2px 0"><span>{{ t('pos.subtotal') }}</span><span>{{ money(liveOrderTotal) }}</span></div>
                        <div v-if="vatPreview > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding:2px 0"><span>{{ t('restaurant.vatEstimate') }}</span><span>{{ money(vatPreview) }}</span></div>
                    </div>

                    <!-- order flips to bill-first when the shop's payment_timing preference is pay_first — the actions themselves are identical either way, only which one a cashier sees emphasized first changes -->
                    <template v-if="payFirst">
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn sm" style="flex:1" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="openBillSheet">{{ splitMode ? t('restaurant.billSelected') : t('restaurant.billNow') }}</button>
                        </div>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn ghost sm" style="flex:1" @click="printKot">{{ t('restaurant.printKot') }}</button>
                            <button v-if="kitchenWaNumber" class="btn wa sm" style="flex:1" @click="sendKotWA">📤 {{ t('restaurant.kotWa') }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn ghost sm" style="flex:1" @click="printKot">{{ t('restaurant.printKot') }}</button>
                            <button v-if="kitchenWaNumber" class="btn wa sm" style="flex:1" @click="sendKotWA">📤 {{ t('restaurant.kotWa') }}</button>
                        </div>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn sm" style="flex:1" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="openBillSheet">{{ splitMode ? t('restaurant.billSelected') : t('restaurant.billNow') }}</button>
                        </div>
                    </template>
                    <button class="btn" style="margin-bottom:6px;background:var(--green)" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="billAndPrintKot">
                        🧾 {{ t('restaurant.billAndKot') }}
                    </button>
                    <button v-if="kitchenWaNumber" class="btn wa" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="billAndSendKotWA">
                        📤 {{ t('restaurant.billAndKotWa') }}
                    </button>
                </div>
            </div>

            <!-- category rail — desktop only, the mobile tabbar below covers the same job on phone -->
            <aside class="hidden lg:block lg:order-1 lg:w-52 lg:shrink-0 lg:sticky lg:top-6">
                <button class="rest-rail-btn" :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                <button v-for="c in categories" :key="c.id" class="rest-rail-btn" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
            </aside>

            <!-- menu -->
            <div class="lg:order-2 lg:flex-1 lg:min-w-0">
                <div style="display:flex;gap:8px;margin-bottom:12px">
                    <input v-model="q" :placeholder="t('pos.searchPlaceholder')" style="flex:1">
                    <button v-if="canScan" class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openScanner">📷</button>
                </div>
                <div class="tabbar lg:hidden">
                    <button :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                    <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
                </div>

                <div v-if="filtered.length" class="pgrid rest-grid">
                    <div v-for="p in filtered" :key="p.id" class="pcard" :class="{ incart: qtyInOrder(p.id) }">
                        <span v-if="qtyInOrder(p.id)" class="qbadge">{{ qtyInOrder(p.id) }}</span>
                        <!-- 'toggle' sold-out is the one case actually disabled here (a real,
                             owner-set "not today" state) — 'untracked'/'tracked' always stay
                             clickable and let the server give the real answer, same reasoning
                             as postItem()'s error toast; this is not the old silent-no-op bug -->
                        <button class="pcard-main" @click="addItem(p)" :disabled="p.stock_mode === 'toggle' && p.stock <= 0">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div class="pp">{{ money(p.price) }}</div>
                            <div class="ps">
                                <span v-if="p.stock_mode === 'untracked'" style="color:var(--mut)">{{ t('pos.alwaysAvailable') }}</span>
                                <span v-else-if="p.stock_mode === 'toggle'" :style="{ color: p.stock > 0 ? 'var(--mut)' : 'var(--rose)' }">{{ p.stock > 0 ? t('pos.availableToday') : t('pos.soldOutToday') }}</span>
                                <span v-else-if="p.stock > 0">{{ t('pos.inStock', { n: p.stock }) }}</span>
                                <span v-else style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                            </div>
                        </button>
                    </div>
                </div>
                <div v-else class="empty"><div class="big">🔍</div>{{ t('pos.notFound') }}</div>
            </div>
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

        <!-- KOT print view — kitchen ticket, no prices -->
        <Teleport to="body">
            <!-- bound to metaForm (the live textboxes), not the order prop —
                 a note/order-type edit that hasn't been explicitly saved yet
                 must still show up here, since printKot() prints instantly,
                 synchronously, before any save round-trip can complete -->
            <div id="printable-kot">
                <h3>{{ t('restaurant.kotTitle') }}</h3>
                <div class="rc-sub">
                    {{ t('restaurant.orderNo', { id: order.id }) }}<br>
                    {{ displayName }} • {{ new Date().toLocaleString() }}
                    <template v-if="metaForm.order_source === 'delivery'"><br>🛵 {{ t('restaurant.sourceDelivery') }} — {{ metaForm.delivery_platform }}</template>
                    <template v-else-if="metaForm.order_source === 'takeaway'"><br>🥡 {{ t('restaurant.sourceTakeaway') }}</template>
                </div>
                <div v-for="it in order.items" :key="it.id" class="rc-l">
                    <span>{{ it.product_name }}</span><b>×{{ it.qty }}</b>
                </div>
                <div v-if="metaForm.kitchen_note" class="rc-note">📝 {{ metaForm.kitchen_note }}</div>
            </div>
        </Teleport>

        <!-- billing sheet -->
        <Sheet v-model="billSheet" :title="t('restaurant.billSheetTitle')" wide>
            <div class="checkout-cols">
            <div>
            <div class="field">
                <label>{{ t('pos.mobileLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.mobileHint') }}</span></label>
                <input v-model="customerPhone" inputmode="tel" placeholder="01XXXXXXXXX">
            </div>
            <div class="field">
                <label>{{ t('pos.customerName') }}</label>
                <input v-model="customerName" :placeholder="t('pos.customerName')">
            </div>
            <div class="field">
                <label>{{ t('pos.payment') }}</label>
                <div v-if="payMode !== 'complimentary'" class="seg">
                    <button :class="{ on: payMode === 'cash' }" @click="payMode = 'cash'">{{ t('pay.cash') }}</button>
                    <button :class="{ on: payMode === 'bkash' }" @click="payMode = 'bkash'">{{ t('pay.bkash') }}</button>
                    <button :class="{ on: payMode === 'nagad' }" @click="payMode = 'nagad'">{{ t('pay.nagad') }}</button>
                    <button :class="{ on: payMode === 'credit' }" @click="payMode = 'credit'">{{ t('pay.credit') }}</button>
                </div>
                <button v-if="isOwner && payMode !== 'complimentary'" type="button" class="btn sm ghost" style="margin-top:8px;color:var(--gold2);border-color:var(--gold2)" @click="payMode = 'complimentary'">🎁 {{ t('pay.complimentary') }}</button>
                <div v-if="payMode === 'complimentary'" class="card" style="margin-top:8px;background:var(--goldSoft);border-color:var(--gold2)">
                    <div style="font-size:13px;font-weight:700;color:var(--gold2)">🎁 {{ t('pay.complimentaryActiveHint') }}</div>
                    <button type="button" class="btn sm ghost" style="margin-top:8px" @click="payMode = 'cash'">{{ t('common.cancel') }}</button>
                </div>
            </div>
            <div class="field">
                <label>{{ t('pos.overallDiscount') }}</label>
                <input v-model.number="discount" type="number" placeholder="0" min="0">
            </div>
            </div>
            <div>
            <div class="card" style="margin-bottom:14px">
                <div v-if="effectiveDiscount > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding-bottom:6px"><span>{{ t('pos.overallDiscount') }}</span><span>− {{ money(effectiveDiscount) }}</span></div>
                <div v-if="serviceCharge > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding-bottom:6px"><span>{{ t('pos.serviceCharge') }}</span><span>+ {{ money(serviceCharge) }}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:19px;font-weight:800"><span>{{ t('pos.grandTotal') }}</span><b style="color:var(--gold)">{{ money(billTotal) }}</b></div>
            </div>
            <button class="btn" :disabled="billSubmitting" @click="submitBill">
                {{ billSubmitting ? t('pos.processing') : t('restaurant.billNow') }}
            </button>
            </div>
            </div>
        </Sheet>

        <!-- table move/merge + cancel — rare actions, reachable in one tap from
             the header's ⋯ button instead of sitting in the everyday scroll flow -->
        <Sheet v-model="moreSheet" :title="t('common.more')">
            <button v-if="freeTables.length" class="btn ghost" style="margin-bottom:10px" @click="moreSheet = false; tableActionMode = 'transfer'; tableActionTargetId = ''; tableActionSheet = true">🔀 {{ t('restaurant.transferTable') }}</button>
            <button v-if="otherOccupiedTables.length" class="btn ghost" style="margin-bottom:10px" @click="moreSheet = false; tableActionMode = 'merge'; tableActionTargetId = ''; tableActionSheet = true">🔗 {{ t('restaurant.mergeTable') }}</button>
            <button class="btn ghost" style="color:var(--rose);border-color:var(--rose)" @click="moreSheet = false; cancelOrder()">{{ t('restaurant.cancelOrder') }}</button>
        </Sheet>

        <!-- table transfer / merge -->
        <Sheet v-model="tableActionSheet" :title="tableActionMode === 'transfer' ? t('restaurant.transferTable') : t('restaurant.mergeTable')">
            <div class="field">
                <label>{{ tableActionMode === 'transfer' ? t('restaurant.transferToLabel') : t('restaurant.mergeFromLabel') }}</label>
                <select v-model="tableActionTargetId">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="t2 in (tableActionMode === 'transfer' ? freeTables : otherOccupiedTables)" :key="t2.id" :value="t2.id">{{ t2.name }}</option>
                </select>
            </div>
            <div style="font-size:12px;color:var(--dim);margin-bottom:14px">
                {{ tableActionMode === 'transfer' ? t('restaurant.transferHint') : t('restaurant.mergeHint') }}
            </div>
            <button class="btn" :disabled="!tableActionTargetId" @click="submitTableAction">{{ t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="tableActionSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>

<style>
@media print {
    @page { size: 58mm auto; margin: 0; }
    #app { display: none !important; }
    #printable-kot {
        width: 58mm; padding: 2mm 3mm; font-family: monospace; font-size: 12px;
    }
    #printable-kot h3 { font-size: 14px; margin: 0 0 4px; text-align: center; }
    #printable-kot .rc-sub { font-size: 10px; text-align: center; margin-bottom: 6px; }
    #printable-kot .rc-l { display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px dashed #999; }
    #printable-kot .rc-note { margin-top: 6px; padding-top: 4px; border-top: 1px dashed #999; font-weight: bold; white-space: pre-wrap; }
}
#printable-kot { display: none; }
@media print { #printable-kot { display: block; } }
</style>
