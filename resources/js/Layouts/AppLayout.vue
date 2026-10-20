<script setup>
import { Link, usePage, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import Toast from '@/Components/Toast.vue';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';
import Sheet from '@/Components/Sheet.vue';
import ReceiptMemo from '@/Components/ReceiptMemo.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { usePermissions } from '@/composables/usePermissions';
import { buildOfflineSaleSnapshot } from '@/support/offlineSaleSnapshot';
import { buildReceiptWhatsAppText, whatsAppUrlFor } from '@/support/receiptMessage';
import { canDiscardEntry as canDiscardQueuedEntry } from '@/support/offlineQueue';

const props = defineProps({ active: { type: String, default: 'home' } });

const page = usePage();
const offlineSync = useOfflineSync();
// Pending-sales sheet now shows every still-unsynced entry (pending AND
// failed), not just failures — open state moved into useOfflineSync.js
// itself (pendingSheetOpen) so Pos/Index.vue's own "pending sales" button
// can open the SAME sheet this file renders, not a second instance.

// Offline-sync resolution UI (see docs/FIX_PLAN.md — the "product not
// found" fix). Only one remap-in-progress or one cancel-in-progress at a
// time, keyed by entry+item id, rather than per-row component state —
// simpler than it sounds since the failed sheet only ever shows a
// handful of stuck entries at once.
const activeRemap = ref(null); // { entryId, productId } | null
const remapQuery = ref('');
const remapResults = ref([]);
let remapSearchTimer = null;
function openRemap(entryId, productId) {
    activeRemap.value = { entryId, productId };
    remapQuery.value = '';
    remapResults.value = [];
}
function closeRemap() {
    activeRemap.value = null;
    remapQuery.value = '';
    remapResults.value = [];
}
watch(remapQuery, (q) => {
    clearTimeout(remapSearchTimer);
    if (!q || q.trim().length < 2) {
        remapResults.value = [];
        return;
    }
    remapSearchTimer = setTimeout(async () => {
        try {
            const res = await fetch(route('app.products.search') + '?q=' + encodeURIComponent(q.trim()));
            const data = await res.json();
            remapResults.value = data.results || [];
        } catch {
            remapResults.value = [];
        }
    }, 300);
});
function pickRemapResult(product) {
    if (!activeRemap.value) return;
    offlineSync.resolveItem(activeRemap.value.entryId, activeRemap.value.productId, product.id, product.name);
    closeRemap();
}

// Full read-only bill breakdown for a failed entry — the cashier asked:
// seeing just a bare product name isn't enough to decide whether to
// drop/remap/cancel a stuck sale; they need to see what was actually in
// it (qty, price, discount, how it was being paid) the same way they'd
// look at any other receipt. Everything here reads straight from the
// already-stored payload (no server round-trip) — unit_price is a
// display-only snapshot (see Pos/Index.vue's lineSnapshot()), never the
// authoritative price, so the total shown here is clearly labeled
// approximate; the real figure is only ever decided at actual sync time.
function billSummary(entry) {
    const problemIds = new Set((entry.problemItems || []).map((p) => p.product_id));
    const items = (entry.payload.items || []).map((item) => {
        const price = item.unit_price;
        const lineTotal = price != null ? price * item.qty - (item.discount || 0) : null;
        return {
            ...item,
            isProblem: problemIds.has(item.product_id),
            lineTotal,
        };
    });
    const knownTotal = items.reduce((sum, i) => (i.lineTotal != null ? sum + i.lineTotal : sum), 0);
    const hasUnknownPrice = items.some((i) => i.lineTotal == null);
    const overallDiscount = entry.payload.discount || 0;
    const payments = entry.payload.payments || [];
    const tendered = payments.reduce((sum, p) => sum + Number(p.amount || 0), 0);
    const approxTotal = Math.max(0, knownTotal - overallDiscount);
    return {
        items,
        hasUnknownPrice,
        overallDiscount,
        couponCode: entry.payload.coupon_code || null,
        payments,
        tendered,
        approxTotal,
        isCredit: payments.length === 0 && !entry.payload.complimentary,
        approxDue: Math.max(0, approxTotal - tendered),
        customerName: entry.payload.customer_name || null,
        customerPhone: entry.payload.customer_phone || null,
        complimentary: !!entry.payload.complimentary,
    };
}

const cancelingEntryId = ref(null);
const cancelReason = ref('');
function startCancel(entryId) {
    cancelingEntryId.value = entryId;
    cancelReason.value = '';
}
function confirmCancel() {
    offlineSync.cancelWithReason(cancelingEntryId.value, cancelReason.value);
    cancelingEntryId.value = null;
    cancelReason.value = '';
}

// "can this person discard this specific entry" (docs/FIX_PLAN.md Phase
// 12): anyone with the shop's return/void permission (the same one that
// already gates reversing a REAL synced sale) can discard any stuck
// offline entry; a cashier without it can only discard their OWN —
// queuedByUserId is local-only attribution (see offlineQueue.js), never
// server-validated, because this data never reached the server to begin
// with; this is a UI-level courtesy, not an enforceable security boundary.
function canDiscardEntry(entry) {
    return canDiscardQueuedEntry(entry, hasPerm('returns'), page.props.auth?.user?.id);
}

// Print/WhatsApp for a STILL-QUEUED (pending or failed) entry, reachable
// any time later from the pending-sales sheet — not just the one-shot
// moment right after checkout that Pos/Index.vue's own receipt sheet
// already covers. Builds the same display-only snapshot either way.
const printPreviewEntry = ref(null);
const printPreviewOpen = ref(false);
const printPreviewSale = computed(() => (printPreviewEntry.value ? buildOfflineSaleSnapshot(printPreviewEntry.value.payload) : null));
function openPrintPreview(entry) {
    printPreviewEntry.value = entry;
    printPreviewOpen.value = true;
}
function printPendingMemo() {
    window.print();
}
function sendPendingMemoWA() {
    if (!printPreviewSale.value) return;
    if (!offlineSync.isOnline.value) {
        toast(t('pos.whatsappNeedsInternet'));
        return;
    }
    window.open(whatsAppUrlFor(printPreviewSale.value, buildReceiptWhatsAppText(shop.value, printPreviewSale.value, t)), '_blank');
}

// Local→real invoice mapping (see useOfflineSync.js's trySync()) — loaded
// fresh each time the sheet opens, not kept live-reactive, since it only
// ever changes as a side effect of a sync this same session would already
// be refreshing the sheet for anyway.
const recentlySynced = ref([]);
// pendingSheetOpen lives in useOfflineSync.js (module-level) specifically
// so it can be opened from anywhere — this page's own banner, OR a
// "pending sales" button on Pos/Index.vue/Clothing/Pos.vue — a plain
// watch here (rather than a wrapper function every opener would need to
// remember to call) means the synced-map refresh happens no matter which
// one actually opened it.
watch(() => offlineSync.pendingSheetOpen.value, (open) => {
    if (open) offlineSync.getSyncedMap().then((map) => { recentlySynced.value = map; });
});
// "as of when" for the offline banner — a shop owner looking at possibly
// hours-old stock/prices while offline needs to know how stale it is, not
// just a bare "no internet" message
const lastOnlineText = computed(() => {
    if (!offlineSync.lastOnlineAt.value) return null;
    return new Date(offlineSync.lastOnlineAt.value).toLocaleTimeString(lang.value === 'bn' ? 'bn-BD' : 'en-US', { hour: '2-digit', minute: '2-digit' });
});
const impersonating = computed(() => page.props.impersonating);
function stopImpersonating() {
    router.post(route('admin.impersonate.stop'));
}
const shop = computed(() => page.props.shop);
// owner-only branch switcher — null (nothing rendered) for any shop with no
// sibling branches, or for a staff account (always fixed to one branch)
const branches = computed(() => page.props.branches);
function switchBranch(event) {
    const id = event.target.value;
    if (!id) return;
    router.post(route('app.branches.switch', id));
}
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const user = computed(() => page.props.auth?.user);
const { hasPerm, isOwner } = usePermissions();
const hasFeature = (key) => (page.props.features || []).includes(key);
// same signal Stock/Index.vue uses to swap "পণ্য" (product) for "খাবার/মেনু"
// (food/menu) wording on the Stock nav links — see that file's own comment.
// Deliberately the shop's actual business type (shop.business_type_slug,
// see Shop::isRestaurant()), NOT the restaurant_tables feature flag — a
// flag can be granted to a non-restaurant shop (e.g. the flagship demo,
// seeded with every feature to showcase the platform) without making it a
// restaurant. Using the flag here previously routed such a shop's "Sell"
// button to the Tables screen instead of the normal POS, breaking add-to-
// cart entirely for that shop's whole business type.
const isRestaurant = computed(() => shop.value?.business_type_slug === 'restaurant');
const { toast } = useToast();
const { t, lang } = useI18n();

// shop owners get no other warning before the subscription/trial expiry
// date actually locks them out (see CheckSubscriptionActive middleware) —
// this shows the countdown everywhere in the app once it's close, so it's
// never a surprise. Only the owner sees it (a cashier calling the shop
// owner about billing isn't their job); shown on every page since a shop
// owner might not visit Home for days.
const daysLeft = computed(() => {
    if (!shop.value?.subscription_expiry) return null;
    const expiry = new Date(shop.value.subscription_expiry);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    expiry.setHours(0, 0, 0, 0);
    return Math.round((expiry - today) / 86400000);
});
const showExpiryBanner = computed(() => isOwner.value && daysLeft.value !== null && daysLeft.value <= 7);

// entirely optional shift/cash-drawer tracking (see WorkPeriodController) —
// a shop that never opens one never sees any of this UI at all
const activeWorkPeriod = computed(() => page.props.activeWorkPeriod);
const now = ref(Date.now());
let clockTimer = null;
onMounted(() => { clockTimer = setInterval(() => { now.value = Date.now(); }, 30000); });
onBeforeUnmount(() => clearInterval(clockTimer));
const workPeriodElapsed = computed(() => {
    if (!activeWorkPeriod.value) return '';
    const mins = Math.max(0, Math.floor((now.value - new Date(activeWorkPeriod.value.opened_at).getTime()) / 60000));
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
});

const closeShiftSheet = ref(false);
const closeShiftForm = useForm({ closing_cash: '' });
function submitCloseShift() {
    closeShiftForm.post(route('app.workPeriod.close', activeWorkPeriod.value.id), { onSuccess: () => { closeShiftSheet.value = false; closeShiftForm.reset(); } });
}
const expiryBannerText = computed(() => {
    const d = daysLeft.value;
    const plan = shop.value?.plan === 'trial' ? t('expiry.trialWord') : t('expiry.subWord');
    if (d < 0) return t('expiry.expired');
    if (d === 0) return `${plan} ${t('expiry.today')}`;
    return `${plan} ${t('expiry.soon', { d })}`;
});

watch(() => page.props.flash?.success, (msg) => { if (msg) toast(msg); });
watch(() => page.props.flash?.error, (msg) => { if (msg) toast(msg); });

// notification bell — payment-received confirmations and expiry reminders
// (see App\Notifications) show up here, shared on every request via
// HandleInertiaRequests so the unread badge is always current. This is a
// small dropdown anchored near the bell (not the big bottom Sheet used
// elsewhere) — a full-screen sheet was overkill for "you have 1 message".
const notifOpen = ref(false);
const unreadCount = computed(() => page.props.notifications?.unread_count || 0);
const notificationItems = computed(() => page.props.notifications?.items || []);

function toggleNotifications() {
    notifOpen.value = !notifOpen.value;
    // Opening the bell counts as "seen" — clear the badge right away instead
    // of making the user click each message (or a separate mark-all button)
    // just to get the "1" to go away.
    if (notifOpen.value) markAllRead();
}
function markNotificationRead(n) {
    if (n.read) return;
    router.post(route('app.notifications.read', n.id), {}, { preserveScroll: true });
}
function openNotificationWhatsapp(n) {
    window.open(n.whatsapp_url, '_blank');
}
function markAllRead() {
    if (!unreadCount.value) return;
    router.post(route('app.notifications.readAll'), {}, { preserveScroll: true });
}

function logout() {
    if (!confirm(t('common.logoutConfirm'))) return;
    router.post(route('app.logout'));
}

// language toggle, reachable from every page now (not just the "আরো" page)
// — a simple switch since there are only two languages: the button always
// shows the *other* language, tap to switch to it. Mirrored to localStorage
// so /login (no user yet) still remembers the last choice on this browser.
function setLang(value) {
    localStorage.setItem('zaylotix_lang', value);
    router.patch(route('app.settings.lang'), { lang: value }, { preserveScroll: true });
}
function toggleLang() {
    setLang(lang.value === 'bn' ? 'en' : 'bn');
}

function goBack() {
    window.history.back();
}

// One coherent icon family for the desktop sidebar, matching the exact
// stroke convention the mobile bottom tab bar already established (24x24
// viewBox, stroke-width 1.9, fill none) — before this, the sidebar used
// raw emoji for every item while the bottom bar used these hand-drawn
// line icons, on the same layout file. Two icon languages in one nav is
// exactly the "mixed icon styles" inconsistency a coherent product
// shouldn't have — recognition, not decoration (see Section 26).
const ICONS = {
    cart: '<path d="M3 4h2l2.4 11.4a2 2 0 0 0 2 1.6h7.2a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 3v4M16 3v4"/>',
    pot: '<path d="M6 8h12v2a6 6 0 0 1-12 0V8z"/><path d="M6 8V6a2 2 0 0 1 2-2h1"/><path d="M18 10h1a2 2 0 0 0 0-4h-1"/>',
    monitor: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
    invoice: '<path d="M6 3h9l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v4h4"/><path d="M8 12h6M8 16h4"/>',
    gift: '<rect x="3" y="8" width="18" height="13" rx="1"/><path d="M3 8h18v4H3z"/><path d="M12 8v13"/><path d="M12 8c-1.5-4-6-4-6-1.5S9 8 12 8zM12 8c1.5-4 6-4 6-1.5S15 8 12 8z"/>',
    clipboard: '<rect x="6" y="4" width="12" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M9 11h6M9 15h4"/>',
    box: '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
    ruler: '<path d="M3 17l14-14 4 4-14 14z"/><path d="M14 6l2 2M11 9l2 2M8 12l2 2"/>',
    tag: '<path d="M20.5 12.5L12 21l-9-9L11.5 3.5H20a1 1 0 0 1 1 1v8z"/><circle cx="16" cy="8" r="1.3" fill="currentColor" stroke="none"/>',
    truck: '<rect x="1" y="6" width="14" height="11" rx="1"/><path d="M15 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="17.5" cy="19" r="1.6"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
    factory: '<path d="M3 21V10l6 4v-4l6 4V6l6 4v11z"/><path d="M3 21h18"/>',
    phone: '<rect x="7" y="2.5" width="10" height="19" rx="2"/><line x1="10.5" y1="18" x2="13.5" y2="18"/>',
    trash: '<path d="M4 7h16"/><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
    undo: '<path d="M3 12a9 9 0 1 0 2.6-6.3"/><path d="M3 4v5h5"/>',
    hash: '<path d="M5 9h14M5 15h14M9 3L7 21M17 3l-2 18"/>',
    flask: '<path d="M9 3h6M10 3v5l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3"/>',
    pan: '<circle cx="11" cy="13" r="7"/><path d="M18 10h3"/>',
    trending: '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
    briefcase: '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    handshake: '<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2 20c0-3 3-5 6-5s6 2 6 5M10 20c0-3 3-5 6-5s6 2 6 5"/>',
    barChart: '<path d="M4 20V11"/><path d="M11 20V4"/><path d="M18 20v-7"/><path d="M3 20h18"/>',
    trendingDown: '<path d="M23 18l-9.5-9.5-5 5L1 6"/><path d="M17 18h6v-6"/>',
    upload: '<path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M4 21h16"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>',
    activity: '<path d="M3 12h4l2 8 4-16 2 8h6"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.8 1c0 1.2-.8 1.8-1.6 2.4-.6.5-1.2 1-1.2 1.8"/><circle cx="12" cy="17" r=".4" fill="currentColor" stroke="none"/>',
    users: '<circle cx="9" cy="8" r="3.2"/><path d="M3.2 20a5.8 5.8 0 0 1 11.6 0"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.8 20a4.6 4.6 0 0 1 7.4-3.7"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 15l-5-5-9 9"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
};

// The "আরো" page (More.vue) holds a few actions that don't have their own
// route — a cashier form, the shop logo, purchase/damage/return/stock-count
// sheets, and data export — they're all opened as sheets on that one page.
// Linking here with ?open=<key> (read by More.vue on mount) lets the
// desktop sidebar list every one of them directly instead of hiding them
// all behind a single generic "আরো" entry, since a sidebar has the room.
function moreLink(openKey) {
    return route('app.more', openKey ? { open: openKey } : {});
}
function isMoreLinkActive(openKey) {
    if (props.active !== 'more') return false;
    const params = new URLSearchParams(page.url.split('?')[1] || '');
    return (params.get('open') || null) === (openKey || null);
}

// The mobile bottom bar only has room for ONE big "Sell" button (unlike the
// desktop sidebar, which lists Sell and Tables as two separate links) — for
// a shop with table service turned on, that one button has to go straight to
// the table/kitchen flow, or a phone-only cashier would never reach it at all.
const mobileSellHref = computed(() => isRestaurant.value ? route('app.restaurant.tables.index') : route('app.pos'));
const mobileSellActive = computed(() => props.active === 'sell' || props.active === 'restaurant');

// Grouped nav for the desktop sidebar — richer than the 5-icon mobile
// bottom bar since a sidebar has room to show everything at once.
const sidebarGroups = computed(() => [
    {
        label: t('nav.category.sales'),
        items: [
            // ALWAYS visible, first, for every business type — Khaled's
            // explicit correction after an earlier fix hid this item
            // entirely for a restaurant shop (it used to be hardcoded to
            // route('app.pos'), the wrong screen for that vertical, so a
            // prior pass hid it rather than fix the destination). Reusing
            // mobileSellHref/mobileSellActive — the exact same smart
            // routing the mobile bottom nav's single "sell" tab already
            // uses — fixes the actual bug (right destination per business
            // type) without ever hiding the button itself. The separate
            // "টেবিল" item that used to sit here as the restaurant-only
            // stand-in is gone — this one link now covers it.
            { key: 'sell', label: t('nav.sellFull'), href: mobileSellHref.value, icon: 'cart', on: mobileSellActive.value, show: hasPerm('pos') },
            { key: 'reservations', label: t('nav.reservations'), href: route('app.reservations.index'), icon: 'calendar', on: props.active === 'reservations', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
            { key: 'kds', label: t('nav.kds'), href: route('app.kds.index'), icon: 'pot', on: props.active === 'kds', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
            { key: 'cds', label: t('nav.cds'), href: route('app.cds.index'), icon: 'monitor', on: props.active === 'cds', show: hasPerm('pos') && isRestaurant.value && hasFeature('restaurant_tables') },
            { key: 'sales', label: t('nav.salesHistory'), navLabel: t('nav.salesHistoryShort'), href: route('app.sales'), icon: 'receipt', on: props.active === 'sales', show: hasPerm('sales_history') },
            { key: 'due', label: t('nav.dueFull'), navLabel: t('nav.due'), href: route('app.customers'), icon: 'invoice', on: props.active === 'due', show: hasPerm('customers') },
            { key: 'customerList', label: t('customerList.title'), navLabel: t('customerList.titleShort'), href: route('app.customerList.index'), icon: 'users', on: props.active === 'customerList', show: hasPerm('customers') },
            { key: 'promotions', label: t('nav.promotions'), navLabel: t('nav.promotionsShort'), href: route('app.promotions.index'), icon: 'gift', on: props.active === 'promotions', show: hasPerm('promotions') && hasFeature('promotions') },
            { key: 'campaigns', label: t('nav.campaigns'), navLabel: t('nav.campaignsShort'), href: route('app.campaigns.index'), icon: 'gift', on: props.active === 'campaigns', show: hasPerm('promotions') && hasFeature('season_campaigns') },
            { key: 'quotations', label: t('nav.quotations'), href: route('app.quotations.index'), icon: 'clipboard', on: props.active === 'quotations', show: hasPerm('pos') && hasFeature('quotations') },
        ],
    },
    {
        label: t('nav.category.inventory'),
        items: [
            { key: 'stock', label: isRestaurant.value ? t('nav.stockFullRestaurant') : t('nav.stockFull'), navLabel: isRestaurant.value ? t('nav.stockRestaurant') : t('nav.stock'), href: route('app.stock'), icon: 'box', on: props.active === 'stock', show: hasPerm('stock') },
            { key: 'units', label: t('nav.units'), href: route('app.units.index'), icon: 'ruler', on: props.active === 'units', show: hasPerm('stock') },
            { key: 'deadStock', label: t('nav.deadStock'), href: route('app.deadStock.index'), icon: 'box', on: props.active === 'deadStock', show: hasPerm('stock') && hasFeature('dead_stock_clearance') },
            { key: 'catalog', label: t('nav.catalog'), href: route('app.catalog.index'), icon: 'gift', on: props.active === 'catalog', show: hasPerm('stock') && hasFeature('digital_catalog') },
            { key: 'barcode', label: t('nav.barcode'), href: route('app.barcodeLabels.index'), icon: 'tag', on: props.active === 'more' && page.url.startsWith('/app/barcode-labels'), show: hasPerm('barcode_labels') && hasFeature('barcode_printing') },
            { key: 'purchase', label: t('nav.purchase'), href: moreLink('purchase'), icon: 'truck', on: isMoreLinkActive('purchase'), show: hasPerm('purchases') && hasFeature('purchases') },
            { key: 'purchaseHistory', label: t('nav.purchaseHistory'), navLabel: t('nav.purchaseHistoryShort'), href: route('app.purchases.index'), icon: 'history', on: props.active === 'purchaseHistory', show: hasPerm('purchases') && hasFeature('purchases') },
            { key: 'suppliers', label: t('nav.suppliers'), href: route('app.suppliers'), icon: 'factory', on: props.active === 'suppliers', show: hasPerm('purchases') && hasFeature('suppliers') },
            { key: 'serials', label: t('nav.serials'), href: route('app.serials.index'), icon: 'phone', on: props.active === 'serials', show: hasPerm('stock') && hasFeature('serial_tracking') },
            { key: 'damage', label: t('nav.damage'), href: moreLink('damage'), icon: 'trash', on: isMoreLinkActive('damage'), show: hasPerm('damages') && hasFeature('damages') },
            { key: 'return', label: t('nav.return'), href: moreLink('return'), icon: 'undo', on: isMoreLinkActive('return'), show: hasPerm('returns') && hasFeature('returns') },
            { key: 'count', label: t('nav.stockCount'), href: moreLink('count'), icon: 'hash', on: isMoreLinkActive('count'), show: hasPerm('stock_count') && hasFeature('stock_count') },
            { key: 'ingredients', label: t('nav.ingredients'), href: route('app.ingredients.index'), icon: 'flask', on: props.active === 'ingredients', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
            { key: 'preparations', label: t('nav.preparations'), href: route('app.preparations.index'), icon: 'pan', on: props.active === 'preparations', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
            { key: 'estimator', label: t('nav.estimator'), href: route('app.estimator.index'), icon: 'trending', on: props.active === 'estimator', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
        ],
    },
    {
        label: t('nav.category.accounts'),
        items: [
            { key: 'accounts', label: t('nav.accounts'), navLabel: t('nav.accountsShort'), href: route('app.accounts'), icon: 'briefcase', on: props.active === 'accounts', show: hasPerm('accounts') && hasFeature('accounts') },
            { key: 'partners', label: t('nav.partners'), href: route('app.partners.index'), icon: 'handshake', on: props.active === 'partners', show: isOwner.value && hasFeature('partners') },
            { key: 'reports', label: t('nav.reports'), href: route('app.reports'), icon: 'barChart', on: props.active === 'reports', show: hasPerm('reports') && hasFeature('reports') },
            { key: 'expenses', label: t('nav.expenses'), href: route('app.expenses'), icon: 'trendingDown', on: props.active === 'expenses', show: hasPerm('expenses') && hasFeature('expenses') },
            { key: 'export', label: t('nav.export'), href: moreLink('export'), icon: 'upload', on: isMoreLinkActive('export'), show: hasPerm('export') && hasFeature('export') },
        ],
    },
    {
        label: t('nav.category.settings'),
        items: [
            { key: 'cashier', label: t('nav.cashier'), href: route('app.staff.index'), icon: 'user', on: props.active === 'cashier', show: isOwner.value && hasFeature('cashier_management') },
            { key: 'activity', label: t('nav.activity'), href: route('app.activity'), icon: 'activity', on: props.active === 'activity', show: isOwner.value && hasFeature('activity_log') },
            { key: 'help', label: t('nav.help'), href: route('app.help'), icon: 'help', on: props.active === 'help', show: true },
            { key: 'employees', label: t('nav.employees'), href: route('app.employees.index'), icon: 'users', on: props.active === 'employees', show: isOwner.value && hasFeature('hr_payroll') },
            { key: 'logo', label: t('nav.logo'), href: moreLink('logo'), icon: 'image', on: isMoreLinkActive('logo'), show: hasPerm('settings') },
            { key: 'more', label: t('nav.moreAll'), href: moreLink(), icon: 'settings', on: isMoreLinkActive(), show: true },
        ],
    },
]);

// Missing feature (see docs/FIX_PLAN.md Phase 8): the mobile bottom nav's
// two flexible slots (between the always-fixed Home/Sell/More) were
// hardcoded to Stock + Due for every shop regardless of business type.
// Reuses the exact same item definitions (href/icon/show) already computed
// for the desktop sidebar — never a second, separately-maintained list —
// restricted to a curated subset sensible as a one-tap bottom-nav
// destination (kept in sync with SettingController::updateMobileNav()'s
// own whitelist). Falls back to Shop::MOBILE_NAV_DEFAULT (['stock','due'])
// when unset, and silently skips a saved key that no longer resolves to
// anything visible (e.g. a feature since turned off) rather than rendering
// a dead tab.
const MOBILE_NAV_CANDIDATE_KEYS = ['stock', 'due', 'sales', 'customerList', 'reports', 'quotations', 'purchaseHistory', 'suppliers', 'promotions', 'accounts', 'expenses'];
const mobileNavCandidates = computed(() => sidebarGroups.value.flatMap((g) => g.items).filter((i) => MOBILE_NAV_CANDIDATE_KEYS.includes(i.key)));
const mobileNavSlots = computed(() => {
    const picked = shop.value?.mobile_nav_items || ['stock', 'due'];
    return picked
        .map((key) => mobileNavCandidates.value.find((i) => i.key === key))
        .filter((i) => i && i.show)
        // navLabel (set on a handful of items above, see their own comment)
        // is a short, bottom-nav-specific label — falls back to the item's
        // normal sidebar label when no shorter variant was defined.
        .map((i) => ({ ...i, label: i.navLabel || i.label }));
});
</script>

<template>
    <div class="lg:flex lg:min-h-screen lg:bg-gray-50">
        <!-- desktop sidebar (web view) — hidden on phone/APK widths, where the
             bottom tab bar below is used instead -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 lg:shrink-0 lg:bg-white lg:border-r lg:sticky lg:top-0 lg:h-screen">
            <div class="p-5 border-b flex items-center gap-2">
                <!-- a shop's own uploaded logo (More → logo) takes priority here
                     over the platform's own brand mark — this header is the one
                     place a shop is "branded" in daily use, unlike receipts/
                     labels which always show the shop's logo regardless -->
                <img v-if="shop?.logo_url" :src="shop.logo_url" class="w-[34px] h-[34px] object-contain rounded-lg shrink-0">
                <img v-else-if="platformLogoUrl" :src="platformLogoUrl" class="w-[34px] h-[34px] object-contain rounded-lg shrink-0">
                <ZaylotixMark v-else :size="34" />
                <div>
                    <div class="font-extrabold text-base leading-tight" style="color:var(--green)">Zaylotix POS</div>
                    <div class="text-[11px] text-gray-400 leading-tight" v-if="shop">{{ shop.name }}</div>
                </div>
            </div>

            <div v-if="branches" class="px-3 pt-3">
                <select :value="shop?.id" style="font-size:12.5px;margin:0" @change="switchBranch">
                    <option v-for="b in branches" :key="b.id" :value="b.id">🏬 {{ b.name }}{{ b.area ? ' — ' + b.area : '' }}</option>
                </select>
            </div>

            <nav class="flex-1 p-3 space-y-3 overflow-y-auto">
                <Link
                    :href="route('app.home')"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium"
                    :class="active === 'home' ? 'nav-item-on' : 'text-gray-600 hover:bg-gray-100'"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0" v-html="ICONS.home"></svg> {{ t('nav.home') }}
                </Link>

                <div v-for="group in sidebarGroups" :key="group.label">
                    <template v-if="group.items.some(i => i.show)">
                        <div class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ group.label }}</div>
                        <Link
                            v-for="item in group.items.filter(i => i.show)" :key="item.key"
                            :href="item.href"
                            class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium"
                            :class="item.on ? 'nav-item-on' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0" v-html="ICONS[item.icon]"></svg> {{ item.label }}
                        </Link>
                    </template>
                </div>
            </nav>

            <div class="p-3 border-t space-y-1">
                <button v-if="isOwner" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100" @click="toggleNotifications">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0" v-html="ICONS.bell"></svg> {{ t('notif.title') }}
                    <span v-if="unreadCount" class="ml-auto min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center">{{ unreadCount }}</span>
                </button>
                <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100" @click="toggleLang">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0" v-html="ICONS.globe"></svg> {{ lang === 'bn' ? 'English' : 'বাংলা' }}
                </button>
                <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-rose-600 hover:bg-rose-50" @click="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 shrink-0" v-html="ICONS.logout"></svg> {{ t('common.logout') }}
                </button>
            </div>

            <div class="p-4 border-t text-center text-[11px] text-gray-400">
                A <a href="https://zaylotix.com/" target="_blank" class="font-semibold" style="color:var(--green)">Zaylotix</a> product
                <template v-if="shop?.support_contact_name || shop?.support_contact_phone"><br>
                    {{ t('more.supportContact') }}:
                    <span v-if="shop?.support_contact_name" class="font-semibold text-gray-600">{{ shop.support_contact_name }}</span>
                    <a v-if="shop?.support_contact_phone" :href="'tel:' + shop.support_contact_phone" class="font-semibold text-gray-600">{{ shop.support_contact_phone }}</a>
                </template>
            </div>
        </aside>

        <!-- the mobile/APK phone-frame UI — full width on phone, centered
             alongside the sidebar on desktop -->
        <div class="lg:flex-1 lg:flex lg:justify-center lg:py-6">
            <div id="phone">
                <div class="brandbar lg:hidden">
                    A <a href="https://zaylotix.com/" target="_blank" rel="noopener">Zaylotix →</a> product
                    <template v-if="shop?.support_contact_name">&nbsp;•&nbsp; {{ shop.support_contact_name }}</template>
                    <template v-if="shop?.support_contact_phone">&nbsp;•&nbsp; <a :href="'tel:' + shop.support_contact_phone">{{ shop.support_contact_phone }}</a></template>
                </div>

                <div id="top" class="lg:hidden">
                    <!-- see the desktop sidebar's matching comment above — same priority order.
                         Compacted (Phase 8, see docs/FIX_PLAN.md): smaller logo, and the logout
                         button dropped its text label to match the bell/lang icon-only circles —
                         together these free up noticeably more width for the shop name/branch
                         picker next to them on a narrow phone. -->
                    <img v-if="shop?.logo_url" :src="shop.logo_url" style="width:32px;height:32px;object-fit:contain;border-radius:var(--radius-sm);flex:0 0 auto">
                    <img v-else-if="platformLogoUrl" :src="platformLogoUrl" style="width:32px;height:32px;object-fit:contain;border-radius:var(--radius-sm);flex:0 0 auto">
                    <ZaylotixMark v-else :size="32" />
                    <div class="btitle">
                        <b style="font-size:15.5px;display:block;line-height:1.15">Zaylotix POS</b>
                        <span style="font-size:11px;color:var(--dim)" v-if="shop && !branches">{{ shop.name }} • {{ shop.area }}</span>
                        <select v-if="branches" :value="shop?.id" style="font-size:11px;margin:2px 0 0;padding:2px 6px;height:auto" @change="switchBranch">
                            <option v-for="b in branches" :key="b.id" :value="b.id">🏬 {{ b.name }}{{ b.area ? ' — ' + b.area : '' }}</option>
                        </select>
                    </div>
                    <button
                        v-if="isOwner"
                        style="margin-left:auto;position:relative;border:1.5px solid var(--line2);border-radius:99px;width:33px;height:33px;color:var(--green);background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:16px;flex:0 0 auto"
                        @click="toggleNotifications"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px" v-html="ICONS.bell"></svg>
                        <span v-if="unreadCount" style="position:absolute;top:-3px;right:-3px;min-width:16px;height:16px;padding:0 3px;border-radius:9px;background:var(--rose);color:#fff;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center">{{ unreadCount }}</span>
                    </button>
                    <button
                        :style="`${isOwner ? 'margin-left:6px' : 'margin-left:auto'};border:1.5px solid var(--line2);border-radius:99px;width:33px;height:33px;color:var(--green);background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:800;flex:0 0 auto`"
                        :title="lang === 'bn' ? 'English' : 'বাংলা'"
                        @click="toggleLang"
                    >
                        {{ lang === 'bn' ? 'EN' : 'বাং' }}
                    </button>
                    <button
                        class="langbtn"
                        style="margin-left:6px;border:1.5px solid var(--line2);border-radius:99px;width:33px;height:33px;color:var(--green);background:var(--panel);display:flex;align-items:center;justify-content:center;flex:0 0 auto"
                        :title="t('common.logout')"
                        @click="logout"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px" v-html="ICONS.logout"></svg>
                    </button>
                </div>

                <div id="view">
                    <button v-if="impersonating" class="expiry-banner urgent" style="width:100%;text-align:left;cursor:pointer" @click="stopImpersonating">
                        🕵️ {{ t('impersonate.banner', { admin: impersonating.adminName || 'Admin' }) }}
                    </button>
                    <div v-if="!offlineSync.isOnline.value" class="expiry-banner urgent">
                        📴 {{ t('offline.banner') }}
                        <span v-if="lastOnlineText">{{ t('offline.lastSyncedAt', { time: lastOnlineText }) }}</span>
                    </div>
                    <!-- Last-chance countdown before 'auto' mode actually
                         sends anything (docs/FIX_PLAN.md Phase 12) — shown
                         only in auto mode (maybeAutoSync() never starts it
                         in review mode at all), separate from the plain
                         pending/failed banner below. -->
                    <div v-if="offlineSync.autoSyncCountdown.value !== null" class="expiry-banner" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                        <span>⏳ {{ t('offline.autoSyncCountdown', { n: offlineSync.pendingCount.value, s: offlineSync.autoSyncCountdown.value }) }}</span>
                        <button type="button" class="btn sm ghost" style="width:auto;padding:4px 12px" @click="offlineSync.cancelAutoSync">{{ t('offline.stopSync') }}</button>
                    </div>
                    <button v-if="offlineSync.pendingCount.value || offlineSync.failedCount.value" class="expiry-banner" style="width:100%;text-align:left;cursor:pointer" @click="offlineSync.pendingSheetOpen.value = true">
                        <template v-if="offlineSync.failedCount.value">⚠️ {{ t('offline.failedCount', { n: offlineSync.failedCount.value }) }}</template>
                        <template v-else>🔄 {{ t('offline.pendingCount', { n: offlineSync.pendingCount.value }) }}</template>
                    </button>
                    <div v-if="showExpiryBanner" class="expiry-banner" :class="{ urgent: daysLeft <= 1 }">
                        ⏰ {{ expiryBannerText }} <a href="tel:01979894356">01979894356</a>
                    </div>
                    <button v-if="activeWorkPeriod" class="expiry-banner" style="width:100%;text-align:left;cursor:pointer" @click="closeShiftSheet = true">
                        🕒 {{ t('workPeriod.running', { time: workPeriodElapsed }) }}
                    </button>
                    <button v-if="active !== 'home'" class="backbtn" @click="goBack">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 19l-7-7 7-7" /></svg>
                        {{ t('common.back') }}
                    </button>
                    <slot />
                </div>

                <nav id="nav" class="lg:hidden">
                    <Link :href="route('app.home')" class="tab" :class="{ on: active === 'home' }">
                        <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" /></svg>
                        <span>{{ t('nav.home') }}</span>
                    </Link>
                    <Link v-if="mobileNavSlots[0]" :href="mobileNavSlots[0].href" class="tab" :class="{ on: mobileNavSlots[0].on }">
                        <svg viewBox="0 0 24 24" v-html="ICONS[mobileNavSlots[0].icon]"></svg>
                        <span>{{ mobileNavSlots[0].label }}</span>
                    </Link>
                    <Link v-if="hasPerm('pos')" :href="mobileSellHref" class="tab sell" :class="{ on: mobileSellActive }">
                        <span class="fab"><svg viewBox="0 0 24 24"><path d="M4 7V5a1 1 0 0 1 1-1h2M4 17v2a1 1 0 0 0 1 1h2M20 7V5a1 1 0 0 0-1-1h-2M20 17v2a1 1 0 0 1-1 1h-2M4 12h16" /></svg></span>
                        <span>{{ t('nav.sell') }}</span>
                    </Link>
                    <Link v-if="mobileNavSlots[1]" :href="mobileNavSlots[1].href" class="tab" :class="{ on: mobileNavSlots[1].on }">
                        <svg viewBox="0 0 24 24" v-html="ICONS[mobileNavSlots[1].icon]"></svg>
                        <span>{{ mobileNavSlots[1].label }}</span>
                    </Link>
                    <Link :href="route('app.more')" class="tab" :class="{ on: active === 'more' }">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="6" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="12" cy="18" r="1.6" /></svg>
                        <span>{{ t('nav.more') }}</span>
                    </Link>
                </nav>
            </div>
        </div>
    </div>

    <template v-if="notifOpen">
        <div class="notif-scrim" @click="notifOpen = false"></div>
        <div class="notif-dropdown">
            <div class="notif-head">
                <b>🔔 {{ t('notif.title') }}</b>
                <button v-if="unreadCount" class="notif-markall" @click="markAllRead">{{ t('notif.markAll') }}</button>
            </div>
            <div v-if="notificationItems.length" class="notif-list">
                <div
                    v-for="n in notificationItems" :key="n.id"
                    class="notif-item" :class="{ unread: !n.read }"
                    @click="markNotificationRead(n)"
                >
                    <b>{{ n.title }}</b>
                    <span style="white-space:pre-line">{{ n.message }}</span>
                    <span class="notif-time">{{ n.created_at }}</span>
                    <button v-if="n.whatsapp_url" type="button" class="btn wa sm" style="margin-top:6px" @click.stop="openNotificationWhatsapp(n)">
                        💬 {{ t('notif.sendWhatsapp') }}
                    </button>
                </div>
            </div>
            <div v-else class="notif-empty">🔔 {{ t('notif.empty') }}</div>
        </div>
    </template>

    <Sheet v-model="offlineSync.pendingSheetOpen.value" :title="t('offline.pendingSalesTitle')">
        <div v-if="shop?.offline_sync_mode === 'review'" style="font-size:12.5px;color:var(--mut);margin-bottom:10px">ℹ️ {{ t('offline.reviewModeHint') }}</div>
        <div v-if="!offlineSync.queue.value.length" class="empty"><div class="big">✅</div>{{ t('offline.nothingPending') }}</div>
        <div v-for="entry in offlineSync.queue.value" :key="entry.id" class="card" style="margin-bottom:10px">
            <div class="row" style="cursor:default;padding:0 0 8px">
                <div class="ava pill" :class="entry.status === 'pending' ? 'sky' : 'rose'" style="border-radius:12px;padding:0;width:42px;height:42px;font-size:18px">{{ entry.status === 'pending' ? '🔄' : '⚠️' }}</div>
                <div class="mid">
                    <b>{{ t('offline.saleQueuedAt', { time: new Date(entry.queuedAt).toLocaleString() }) }}</b>
                    <span v-if="entry.error">{{ entry.error }}</span>
                    <span v-else>{{ t('offline.waitingToSync') }}</span>
                </div>
            </div>

            <!-- Full read-only bill breakdown — see billSummary()'s own
                 comment for why this exists: a bare product name isn't
                 enough to decide what to do with a stuck sale. -->
            <div v-if="entry.payload?.items?.length" style="padding:8px 0;border-top:1px solid var(--line)">
                <div v-for="item in billSummary(entry).items" :key="item.product_id" class="row" style="cursor:default;padding:4px 0" :style="item.isProblem ? 'background:var(--roseSoft);border-radius:8px' : ''">
                    <div class="mid">
                        <b>{{ item.isProblem ? '❓ ' : '' }}{{ item.product_name || t('offline.unknownProduct') }}<span v-if="item.variant_label"> ({{ item.variant_label }})</span></b>
                        <span>{{ item.qty }}{{ item.unit_label ? ' ' + item.unit_label : '' }} × {{ item.unit_price != null ? '৳' + item.unit_price : '—' }}<template v-if="item.discount"> − ৳{{ item.discount }} {{ t('offline.discountAbbrev') }}</template></span>
                    </div>
                    <div class="end">{{ item.lineTotal != null ? '৳' + item.lineTotal.toFixed(2) : '—' }}</div>
                </div>

                <div class="row" style="cursor:default;padding:6px 0;border-top:1px dashed var(--line2)">
                    <div class="mid">
                        <b>{{ t('offline.approxTotal') }}</b>
                        <span v-if="billSummary(entry).hasUnknownPrice">{{ t('offline.priceUnknownHint') }}</span>
                        <span v-else-if="billSummary(entry).complimentary">{{ t('offline.complimentarySale') }}</span>
                        <span v-else-if="billSummary(entry).payments.length">{{ billSummary(entry).payments.map(p => `${p.method}: ৳${p.amount}`).join(', ') }}</span>
                        <span v-else>{{ t('offline.fullyDue') }}{{ billSummary(entry).customerName ? ' — ' + billSummary(entry).customerName : '' }}{{ billSummary(entry).customerPhone ? ' (' + billSummary(entry).customerPhone + ')' : '' }}</span>
                    </div>
                    <div class="end"><b>৳{{ billSummary(entry).approxTotal.toFixed(2) }}</b></div>
                </div>
                <div v-if="!billSummary(entry).complimentary && billSummary(entry).approxDue > 0 && !billSummary(entry).hasUnknownPrice" style="font-size:12.5px;color:var(--rose);padding:2px 0 0">
                    {{ t('offline.approxDue') }}: ৳{{ billSummary(entry).approxDue.toFixed(2) }}
                </div>
            </div>

            <!-- Permanent failure WITH named problem items — fix each one
                 in place (never discard the rest of the bill over it). -->
            <template v-if="entry.status === 'failed' && entry.failureKind === 'permanent' && entry.problemItems?.length">
                <div v-for="item in entry.problemItems" :key="item.product_id" style="padding:8px 0;border-top:1px solid var(--line)">
                    <div style="font-size:13px;margin-bottom:6px">❓ {{ item.product_name || t('offline.unknownProduct') }}</div>
                    <template v-if="activeRemap?.entryId === entry.id && activeRemap?.productId === item.product_id">
                        <input v-model="remapQuery" :placeholder="t('offline.searchProduct')" style="margin-bottom:6px">
                        <div v-for="r in remapResults" :key="r.id" class="row" style="cursor:pointer;padding:6px 0" @click="pickRemapResult(r)">
                            <div class="mid"><b>{{ r.name }}</b><span>৳{{ r.price }} · {{ t('offline.stockAbbrev') }}: {{ r.stock }}</span></div>
                        </div>
                        <button class="btn sm ghost" style="width:100%" @click="closeRemap">{{ t('common.cancel') }}</button>
                    </template>
                    <div v-else class="btnrow">
                        <button class="btn sm ghost" style="flex:1" @click="openRemap(entry.id, item.product_id)">{{ t('offline.matchAnother') }}</button>
                        <button class="btn sm ghost" style="flex:1" @click="offlineSync.dropItem(entry.id, item.product_id)">{{ t('offline.dropItem') }}</button>
                    </div>
                </div>
            </template>

            <div v-if="cancelingEntryId === entry.id" style="padding-top:8px;border-top:1px solid var(--line)">
                <input v-model="cancelReason" :placeholder="t('offline.cancelReasonPlaceholder')" style="margin-bottom:6px">
                <div class="btnrow">
                    <button class="btn sm rose" style="flex:1" @click="confirmCancel">{{ t('offline.confirmCancel') }}</button>
                    <button class="btn sm ghost" style="flex:1" @click="cancelingEntryId = null">{{ t('common.cancel') }}</button>
                </div>
            </div>
            <template v-else>
                <div v-if="entry.payload?.items?.length" class="btnrow" style="margin-top:8px">
                    <button class="btn sm ghost" style="flex:1" @click="openPrintPreview(entry)">🖨️ {{ t('pos.print') }}</button>
                    <button class="btn sm ghost" style="flex:1" @click="openPrintPreview(entry); sendPendingMemoWA()">📤 WhatsApp</button>
                </div>
                <div class="btnrow" style="margin-top:8px">
                    <!-- failureKind !== 'permanent' (not just === 'transient') deliberately
                         also covers a legacy entry queued before this fix existed — it has
                         no failureKind at all yet, so it still deserves a plain retry that
                         re-attempts against the current (fixed) server logic, rather than
                         only ever offering "cancel" for something that might now succeed. -->
                    <button v-if="entry.status === 'pending'" class="btn sm" style="flex:1" @click="offlineSync.trySync()">{{ t('offline.syncNow') }}</button>
                    <button v-else-if="entry.status === 'shop_mismatch' || entry.failureKind !== 'permanent'" class="btn sm" style="flex:1" @click="offlineSync.retry(entry.id)">{{ t('offline.retryAll') }}</button>
                    <button v-if="canDiscardEntry(entry)" class="btn sm ghost" style="flex:1" @click="startCancel(entry.id)">{{ t('offline.cancelThisSale') }}</button>
                    <div v-else style="flex:1;font-size:11.5px;color:var(--dim);align-self:center;text-align:center">{{ t('offline.notYourSale') }}</div>
                </div>
            </template>
        </div>

        <template v-if="recentlySynced.length">
            <div class="sechead"><h2>{{ t('offline.recentlySynced') }}</h2></div>
            <div style="font-size:11.5px;color:var(--dim);margin-bottom:6px">{{ t('offline.recentlySyncedHint') }}</div>
            <div v-for="m in recentlySynced" :key="m.localInvoice" class="row" style="cursor:default">
                <div class="mid"><b>{{ m.localInvoice }} → {{ m.realInvoice }}</b><span>{{ new Date(m.syncedAt).toLocaleString() }}</span></div>
            </div>
        </template>
    </Sheet>

    <Sheet v-model="printPreviewOpen" :title="t('offline.receiptPreviewTitle')">
        <template v-if="printPreviewSale">
            <div id="printable-memo" :class="'paper-' + (shop?.memo_settings?.paper_width || '58mm').replace('mm', '')">
                <ReceiptMemo :shop="shop" :sale="printPreviewSale" :platform-logo-url="page.props.platformLogoUrl" id-suffix="-pending" />
            </div>
            <div class="no-print btnrow" style="margin-top:16px">
                <button class="btn wa" style="flex:1" @click="sendPendingMemoWA">📤 WhatsApp</button>
                <button class="btn ghost" style="flex:1" @click="printPendingMemo">{{ t('pos.print') }}</button>
            </div>
        </template>
    </Sheet>

    <Sheet v-model="closeShiftSheet" :title="t('workPeriod.closeTitle')">
        <div v-if="activeWorkPeriod" style="font-size:12.5px;color:var(--mut);margin-bottom:10px">{{ t('workPeriod.closeHint', { time: workPeriodElapsed }) }}</div>
        <div class="field">
            <label>{{ t('workPeriod.closingCash') }}</label>
            <input v-model="closeShiftForm.closing_cash" type="number" min="0" placeholder="0">
            <div v-if="closeShiftForm.errors.closing_cash" style="color:var(--rose);font-size:12px;margin-top:6px">{{ closeShiftForm.errors.closing_cash }}</div>
        </div>
        <button class="btn" :disabled="closeShiftForm.processing" @click="submitCloseShift">{{ closeShiftForm.processing ? '...' : t('workPeriod.endButton') }}</button>
    </Sheet>

    <Toast />
</template>
