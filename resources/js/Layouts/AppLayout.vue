<script setup>
import { Link, usePage, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import Toast from '@/Components/Toast.vue';
import HelpButton from '@/Components/HelpButton.vue';
import ZaylotixMark from '@/Components/ZaylotixMark.vue';
import Sheet from '@/Components/Sheet.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { useOfflineSync } from '@/composables/useOfflineSync';

const props = defineProps({ active: { type: String, default: 'home' } });

const page = usePage();
const offlineSync = useOfflineSync();
const failedSheet = ref(false);
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
const isOwner = computed(() => user.value?.role === 'owner');
const hasPerm = (key) => isOwner.value || (user.value?.permissions || []).includes(key);
const hasFeature = (key) => (page.props.features || []).includes(key);
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

// friendly Bengali screen name sent along with the WhatsApp help message —
// so support immediately knows which screen the owner is stuck on without
// them having to explain it themselves
const SCREEN_LABELS = {
    home: 'হোম', sell: 'বিক্রি (POS)', restaurant: 'টেবিল', reservations: 'রিজার্ভেশন', kds: 'কিচেন ডিসপ্লে', cds: 'কাস্টমার ডিসপ্লে', stock: 'স্টক', due: 'বাকি',
    sales: 'বিক্রির ইতিহাস', promotions: 'অফার/কুপন', quotations: 'কোটেশন', accounts: 'হিসাব-নিকাশ',
    reports: 'রিপোর্ট', expenses: 'খরচ', purchaseHistory: 'ক্রয়ের ইতিহাস', suppliers: 'সাপ্লায়ার',
    serials: 'IMEI/ওয়ারেন্টি', cashier: 'ক্যাশিয়ার', activity: 'অ্যাক্টিভিটি লগ', more: 'আরও', help: 'সাহায্য', partners: 'পার্টনার হিসাব', employees: 'কর্মচারী ও বেতন',
    ingredients: 'উপাদান ও রেসিপি', preparations: 'প্রস্তুতকরণ', estimator: 'ইনগ্রেডিয়েন্ট এস্টিমেটর',
};
const screenLabel = computed(() => SCREEN_LABELS[props.active] || props.active);

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
const mobileSellHref = computed(() => hasFeature('restaurant_tables') ? route('app.restaurant.tables.index') : route('app.pos'));
const mobileSellActive = computed(() => props.active === 'sell' || props.active === 'restaurant');

// Grouped nav for the desktop sidebar — richer than the 5-icon mobile
// bottom bar since a sidebar has room to show everything at once.
const sidebarGroups = computed(() => [
    {
        label: t('nav.category.sales'),
        items: [
            { key: 'sell', label: t('nav.sellFull'), href: route('app.pos'), icon: '🛒', on: props.active === 'sell', show: hasPerm('pos') },
            { key: 'restaurant', label: t('nav.restaurant'), href: route('app.restaurant.tables.index'), icon: '🍽️', on: props.active === 'restaurant', show: hasPerm('pos') && hasFeature('restaurant_tables') },
            { key: 'reservations', label: t('nav.reservations'), href: route('app.reservations.index'), icon: '📅', on: props.active === 'reservations', show: hasPerm('pos') && hasFeature('restaurant_tables') },
            { key: 'kds', label: t('nav.kds'), href: route('app.kds.index'), icon: '🍳', on: props.active === 'kds', show: hasPerm('pos') && hasFeature('restaurant_tables') },
            { key: 'cds', label: t('nav.cds'), href: route('app.cds.index'), icon: '🖥️', on: props.active === 'cds', show: hasPerm('pos') && hasFeature('restaurant_tables') },
            { key: 'sales', label: t('nav.salesHistory'), href: route('app.sales'), icon: '🧮', on: props.active === 'sales', show: hasPerm('sales_history') },
            { key: 'due', label: t('nav.dueFull'), href: route('app.customers'), icon: '🧾', on: props.active === 'due', show: hasPerm('customers') },
            { key: 'promotions', label: t('nav.promotions'), href: route('app.promotions.index'), icon: '🎁', on: props.active === 'promotions', show: hasPerm('promotions') && hasFeature('promotions') },
            { key: 'quotations', label: t('nav.quotations'), href: route('app.quotations.index'), icon: '📋', on: props.active === 'quotations', show: hasPerm('pos') && hasFeature('quotations') },
        ],
    },
    {
        label: t('nav.category.inventory'),
        items: [
            { key: 'stock', label: t('nav.stockFull'), href: route('app.stock'), icon: '📦', on: props.active === 'stock', show: hasPerm('stock') },
            { key: 'barcode', label: t('nav.barcode'), href: route('app.barcodeLabels.index'), icon: '🏷️', on: props.active === 'more' && page.url.startsWith('/app/barcode-labels'), show: hasPerm('barcode_labels') && hasFeature('barcode_printing') },
            { key: 'purchase', label: t('nav.purchase'), href: moreLink('purchase'), icon: '🚚', on: isMoreLinkActive('purchase'), show: hasPerm('purchases') && hasFeature('purchases') },
            { key: 'purchaseHistory', label: t('nav.purchaseHistory'), href: route('app.purchases.index'), icon: '📜', on: props.active === 'purchaseHistory', show: hasPerm('purchases') && hasFeature('purchases') },
            { key: 'suppliers', label: t('nav.suppliers'), href: route('app.suppliers'), icon: '🏭', on: props.active === 'suppliers', show: hasPerm('purchases') && hasFeature('suppliers') },
            { key: 'serials', label: t('nav.serials'), href: route('app.serials.index'), icon: '📱', on: props.active === 'serials', show: hasPerm('stock') && hasFeature('serial_tracking') },
            { key: 'damage', label: t('nav.damage'), href: moreLink('damage'), icon: '🗑️', on: isMoreLinkActive('damage'), show: hasPerm('damages') && hasFeature('damages') },
            { key: 'return', label: t('nav.return'), href: moreLink('return'), icon: '↩️', on: isMoreLinkActive('return'), show: hasPerm('returns') && hasFeature('returns') },
            { key: 'count', label: t('nav.stockCount'), href: moreLink('count'), icon: '🔢', on: isMoreLinkActive('count'), show: hasPerm('stock_count') && hasFeature('stock_count') },
            { key: 'ingredients', label: t('nav.ingredients'), href: route('app.ingredients.index'), icon: '🧂', on: props.active === 'ingredients', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
            { key: 'preparations', label: t('nav.preparations'), href: route('app.preparations.index'), icon: '🍳', on: props.active === 'preparations', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
            { key: 'estimator', label: t('nav.estimator'), href: route('app.estimator.index'), icon: '🧮', on: props.active === 'estimator', show: hasPerm('stock') && hasFeature('ingredient_tracking') },
        ],
    },
    {
        label: t('nav.category.accounts'),
        items: [
            { key: 'accounts', label: t('nav.accounts'), href: route('app.accounts'), icon: '💼', on: props.active === 'accounts', show: hasPerm('accounts') && hasFeature('accounts') },
            { key: 'partners', label: t('nav.partners'), href: route('app.partners.index'), icon: '🤝', on: props.active === 'partners', show: isOwner.value && hasFeature('partners') },
            { key: 'reports', label: t('nav.reports'), href: route('app.reports'), icon: '📊', on: props.active === 'reports', show: hasPerm('reports') && hasFeature('reports') },
            { key: 'expenses', label: t('nav.expenses'), href: route('app.expenses'), icon: '💸', on: props.active === 'expenses', show: hasPerm('expenses') && hasFeature('expenses') },
            { key: 'export', label: t('nav.export'), href: moreLink('export'), icon: '📤', on: isMoreLinkActive('export'), show: hasPerm('export') && hasFeature('export') },
        ],
    },
    {
        label: t('nav.category.settings'),
        items: [
            { key: 'cashier', label: t('nav.cashier'), href: route('app.staff.index'), icon: '👤', on: props.active === 'cashier', show: isOwner.value && hasFeature('cashier_management') },
            { key: 'activity', label: t('nav.activity'), href: route('app.activity'), icon: '📋', on: props.active === 'activity', show: isOwner.value && hasFeature('activity_log') },
            { key: 'help', label: t('nav.help'), href: route('app.help'), icon: '❓', on: props.active === 'help', show: true },
            { key: 'employees', label: t('nav.employees'), href: route('app.employees.index'), icon: '👥', on: props.active === 'employees', show: isOwner.value && hasFeature('hr_payroll') },
            { key: 'logo', label: t('nav.logo'), href: moreLink('logo'), icon: '🖼️', on: isMoreLinkActive('logo'), show: hasPerm('settings') },
            { key: 'more', label: t('nav.moreAll'), href: moreLink(), icon: '⚙️', on: isMoreLinkActive(), show: true },
        ],
    },
]);
</script>

<template>
    <div class="lg:flex lg:min-h-screen lg:bg-gray-50">
        <!-- desktop sidebar (web view) — hidden on phone/APK widths, where the
             bottom tab bar below is used instead -->
        <aside class="hidden lg:flex lg:flex-col lg:w-64 lg:shrink-0 lg:bg-white lg:border-r lg:sticky lg:top-0 lg:h-screen">
            <div class="p-5 border-b flex items-center gap-2">
                <img v-if="platformLogoUrl" :src="platformLogoUrl" class="w-[34px] h-[34px] object-contain rounded-lg shrink-0">
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
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium"
                    :class="active === 'home' ? 'bg-emerald-50 text-emerald-700' : 'text-gray-600 hover:bg-gray-100'"
                >
                    <span class="text-lg">🏠</span> {{ t('nav.home') }}
                </Link>

                <div v-for="group in sidebarGroups" :key="group.label">
                    <template v-if="group.items.some(i => i.show)">
                        <div class="px-3 pt-1 pb-1 text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ group.label }}</div>
                        <Link
                            v-for="item in group.items.filter(i => i.show)" :key="item.key"
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium"
                            :class="item.on ? 'bg-emerald-50 text-emerald-700' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            <span class="text-lg">{{ item.icon }}</span> {{ item.label }}
                        </Link>
                    </template>
                </div>
            </nav>

            <div class="p-3 border-t space-y-1">
                <button v-if="isOwner" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100" @click="toggleNotifications">
                    <span class="text-lg">🔔</span> {{ t('notif.title') }}
                    <span v-if="unreadCount" class="ml-auto min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center">{{ unreadCount }}</span>
                </button>
                <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100" @click="toggleLang">
                    <span class="text-lg">🌐</span> {{ lang === 'bn' ? 'English' : 'বাংলা' }}
                </button>
                <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-rose-600 hover:bg-rose-50" @click="logout">
                    <span class="text-lg">🚪</span> {{ t('common.logout') }}
                </button>
            </div>

            <div class="p-4 border-t text-center text-[11px] text-gray-400">
                A <a href="https://zaylotix.com/" target="_blank" class="font-semibold" style="color:var(--green)">Zaylotix</a> product<br>
                Owner: <a href="https://khaledbinislam.com/" target="_blank" class="font-semibold text-gray-600">Khaled Bin Islam</a>
            </div>
        </aside>

        <!-- the mobile/APK phone-frame UI — full width on phone, centered
             alongside the sidebar on desktop -->
        <div class="lg:flex-1 lg:flex lg:justify-center lg:py-6">
            <div id="phone">
                <div class="brandbar lg:hidden">
                    A <a href="https://zaylotix.com/" target="_blank" rel="noopener">Zaylotix →</a> product
                    &nbsp;•&nbsp; Owner: <a href="https://khaledbinislam.com/" target="_blank" rel="noopener">Khaled Bin Islam →</a>
                    &nbsp;•&nbsp; <a href="tel:01979894356">01979894356</a>
                </div>

                <div id="top" class="lg:hidden">
                    <img v-if="platformLogoUrl" :src="platformLogoUrl" style="width:40px;height:40px;object-fit:contain;border-radius:12px;flex:0 0 auto">
                    <ZaylotixMark v-else :size="40" />
                    <div class="btitle">
                        <b style="font-size:17px;display:block;line-height:1.15">Zaylotix POS</b>
                        <span style="font-size:11px;color:var(--dim)" v-if="shop && !branches">{{ shop.name }} • {{ shop.area }}</span>
                        <select v-if="branches" :value="shop?.id" style="font-size:11px;margin:2px 0 0;padding:2px 6px;height:auto" @change="switchBranch">
                            <option v-for="b in branches" :key="b.id" :value="b.id">🏬 {{ b.name }}{{ b.area ? ' — ' + b.area : '' }}</option>
                        </select>
                    </div>
                    <button
                        v-if="isOwner"
                        style="margin-left:auto;position:relative;border:1.5px solid var(--line2);border-radius:99px;width:38px;height:38px;color:var(--green);background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:16px"
                        @click="toggleNotifications"
                    >
                        🔔
                        <span v-if="unreadCount" style="position:absolute;top:-3px;right:-3px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:var(--rose);color:#fff;font-size:9.5px;font-weight:800;display:flex;align-items:center;justify-content:center">{{ unreadCount }}</span>
                    </button>
                    <button
                        :style="`${isOwner ? 'margin-left:8px' : 'margin-left:auto'};border:1.5px solid var(--line2);border-radius:99px;width:38px;height:38px;color:var(--green);background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800`"
                        :title="lang === 'bn' ? 'English' : 'বাংলা'"
                        @click="toggleLang"
                    >
                        {{ lang === 'bn' ? 'EN' : 'বাং' }}
                    </button>
                    <button
                        class="langbtn"
                        style="margin-left:8px;border:1.5px solid var(--line2);border-radius:99px;padding:7px 13px;font-size:12.5px;font-weight:700;color:var(--green);background:var(--panel);display:flex;align-items:center;gap:6px"
                        @click="logout"
                    >
                        🚪 {{ t('common.logout') }}
                    </button>
                </div>

                <div id="view">
                    <button v-if="impersonating" class="expiry-banner urgent" style="width:100%;text-align:left;cursor:pointer" @click="stopImpersonating">
                        🕵️ {{ t('impersonate.banner', { admin: impersonating.adminName || 'Admin' }) }}
                    </button>
                    <div v-if="!offlineSync.isOnline.value" class="expiry-banner urgent">
                        📴 {{ t('offline.banner') }}
                    </div>
                    <button v-if="offlineSync.pendingCount.value || offlineSync.failedCount.value" class="expiry-banner" style="width:100%;text-align:left;cursor:pointer" @click="offlineSync.failedCount.value ? (failedSheet = true) : offlineSync.trySync()">
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
                    <Link v-if="hasPerm('stock')" :href="route('app.stock')" class="tab" :class="{ on: active === 'stock' }">
                        <svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4z" /><path d="M3 7v10l9 4 9-4V7" /><path d="M12 11v10" /></svg>
                        <span>{{ t('nav.stock') }}</span>
                    </Link>
                    <Link v-if="hasPerm('pos')" :href="mobileSellHref" class="tab sell" :class="{ on: mobileSellActive }">
                        <span class="fab"><svg viewBox="0 0 24 24"><path d="M4 7V5a1 1 0 0 1 1-1h2M4 17v2a1 1 0 0 0 1 1h2M20 7V5a1 1 0 0 0-1-1h-2M20 17v2a1 1 0 0 1-1 1h-2M4 12h16" /></svg></span>
                        <span>{{ t('nav.sell') }}</span>
                    </Link>
                    <Link v-if="hasPerm('customers')" :href="route('app.customers')" class="tab" :class="{ on: active === 'due' }">
                        <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z" /><path d="M4 10h16" /><path d="M8 15h4" /></svg>
                        <span>{{ t('nav.due') }}</span>
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
                <button
                    v-for="n in notificationItems" :key="n.id"
                    class="notif-item" :class="{ unread: !n.read }"
                    @click="markNotificationRead(n)"
                >
                    <b>{{ n.title }}</b>
                    <span>{{ n.message }}</span>
                    <span class="notif-time">{{ n.created_at }}</span>
                </button>
            </div>
            <div v-else class="notif-empty">🔔 {{ t('notif.empty') }}</div>
        </div>
    </template>

    <Sheet v-model="failedSheet" :title="t('offline.failedTitle')">
        <div v-for="entry in offlineSync.queue.value.filter(e => e.status === 'failed')" :key="entry.id" class="row" style="cursor:default">
            <div class="ava pill rose" style="border-radius:12px;padding:0;width:42px;height:42px;font-size:18px">⚠️</div>
            <div class="mid">
                <b>{{ t('offline.saleQueuedAt', { time: new Date(entry.queuedAt).toLocaleString() }) }}</b>
                <span>{{ entry.error }}</span>
            </div>
        </div>
        <div class="btnrow" style="margin-top:12px">
            <button class="btn sm" style="flex:1" @click="offlineSync.queue.value.filter(e => e.status === 'failed').forEach(e => offlineSync.retry(e.id))">{{ t('offline.retryAll') }}</button>
            <button class="btn sm ghost" style="flex:1" @click="offlineSync.queue.value.filter(e => e.status === 'failed').forEach(e => offlineSync.discard(e.id)); failedSheet = false">{{ t('offline.discardAll') }}</button>
        </div>
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

    <HelpButton :screen-label="screenLabel" />
    <Toast />
</template>
