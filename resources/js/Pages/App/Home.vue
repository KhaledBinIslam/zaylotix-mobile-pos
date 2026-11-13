<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useNavItems } from '@/composables/useNavItems';
import { ICONS } from '@/support/navIcons';

const { t } = useI18n();

const props = defineProps({
    shop: Object,
    todaySale: Number,
    billsToday: Number,
    weekSale: Number,
    monthSale: Number,
    totalDue: Number,
    dueCustomerCount: Number,
    lowStockCount: Number,
    outOfStockCount: Number,
    productCount: Number,
    customerCount: Number,
    recentSales: Array,
    topDue: Array,
    bestSellers: Array,
    onlineStats: Object,
});

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function remindWA(customer) {
    const num = '88' + (customer.phone || '').replace(/\D/g, '').replace(/^88/, '');
    const msg = `বিসমিল্লাহ। ${customer.name},\n\n*${props.shop?.name}* এ আপনার বাকি: *${money(customer.due)}*\n\nসুবিধামত পরিশোধ করলে খুশি হবো। ধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(msg), '_blank');
}

const payModeLabel = { cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split') };
const payModeClass = { cash: 'mint', bkash: 'gold', nagad: 'gold', credit: 'rose', split: 'sky' };

// --- Quick Actions — Khaled's "bKash-style" dashboard shortcut grid ---
// "jetugula feature ache sob nia aso, kosto kore more button e na jate
// cole" — every destination the desktop sidebar can reach, shown here
// too, so nothing is ever hidden a tap deeper behind "আরো". Pulled from
// the SAME useNavItems() composable AppLayout.vue's own sidebar now
// builds its list from (no `active` passed — nothing on this grid is
// ever the "currently open" page, so every item's `on` is simply
// unused here), rather than a second hand-picked list that could drift
// out of sync with what the sidebar actually shows.
const { navGroups } = useNavItems();

// One accent color per category, cycling the same 5 soft tones the app
// already uses elsewhere for this exact purpose (feature pillars,
// platform cards) — keeps 30+ tiles from reading as one flat gray wall.
const ACCENTS = ['gold', 'purple', 'rose', 'sky', 'green'];
function accentFor(categoryIndex, itemIndex) {
    return ACCENTS[(categoryIndex + itemIndex) % ACCENTS.length];
}

// "Supplier Return" has a real route/feature but no sidebar entry of its
// own (it only ever opened from inside More.vue's own list) — added here
// only, as Khaled's "show literally everything" ask, without inventing a
// new sidebar item AppLayout.vue (and therefore every other page) would
// also start showing.
const QUICK_ACTION_GROUPS = computed(() => {
    const groups = navGroups.value;
    const extraInventoryItem = {
        key: 'supplierReturn', icon: 'undo', label: t('more.supplierReturnTitle'),
        href: route('app.more', { open: 'supplierReturn' }),
        show: groups[1]?.items.some((i) => i.key === 'suppliers' && i.show),
    };

    return groups
        .map((group, gi) => ({
            label: group.label,
            items: [...group.items, ...(gi === 1 ? [extraInventoryItem] : [])]
                .filter((item) => item.show)
                .map((item, ii) => ({ ...item, ac: accentFor(gi, ii) })),
        }))
        .filter((group) => group.items.length);
});
</script>

<template>
    <Head :title="t('nav.home')" />
    <AppLayout active="home">
        <div class="home-dash">
            <div class="pgttl">{{ t('home.greeting') }}</div>
            <div class="pgsub">{{ shop?.name }}</div>

            <div class="grid2">
                <div class="stat gold">
                    <div class="k">{{ t('home.todaySale') }}</div>
                    <div class="v">{{ money(todaySale) }}</div>
                    <div class="h">{{ billsToday }} {{ t('home.bills') }}</div>
                </div>
                <div class="stat rose">
                    <div class="k">{{ t('home.totalDue') }}</div>
                    <div class="v">{{ money(totalDue) }}</div>
                    <div class="h">{{ dueCustomerCount }} {{ t('home.customers') }}</div>
                </div>
                <!-- reported: showed a count but tapping did nothing — now
                     opens Stock pre-filtered to exactly these products, same
                     thresholds ProductController uses for this count itself -->
                <Link :href="route('app.stock', { stock_status: 'low' })" class="stat sky">
                    <div class="k">{{ t('home.lowStock') }}</div>
                    <div class="v">{{ lowStockCount }}</div>
                    <div class="h">{{ t('home.products') }}</div>
                </Link>
                <!-- Profit/margin used to be here — moved to Reports (see
                     HomeController's own comment) since Khaled's explicit
                     request was that this screen, seen the instant the app
                     opens, shouldn't lead with an end-of-day accounting
                     figure. Out-of-stock replaces it: outOfStockCount was
                     already being sent here and computed, just never actually
                     shown anywhere — an immediately-actionable count fits this
                     screen's "what needs attention right now" purpose better
                     than a look-back profit number ever did. -->
                <Link :href="route('app.stock', { stock_status: 'out' })" class="stat mint">
                    <div class="k">{{ t('home.outOfStock') }}</div>
                    <div class="v">{{ outOfStockCount }}</div>
                    <div class="h">{{ t('home.products') }}</div>
                </Link>
            </div>

            <!-- Quick Actions — the bKash-style shortcut grid, one section per
                 sidebar category. Every item's own show: already matches the
                 sidebar's identical check for that same destination, so
                 nothing appears here that the shop/cashier couldn't already
                 reach another way — just without going through "আরো" first. -->
            <div v-for="group in QUICK_ACTION_GROUPS" :key="group.label" class="qa-section">
                <div class="qa-section-label">{{ group.label }}</div>
                <div class="qa-grid">
                    <Link v-for="a in group.items" :key="a.key" :href="a.href" class="qa-item">
                        <span class="qa-icon" :class="'ac-' + a.ac">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[a.icon]"></svg>
                        </span>
                        <span class="qa-label">{{ a.label }}</span>
                    </Link>
                </div>
            </div>

            <div class="card" style="margin-top:14px;background:var(--greenSoft);border-color:var(--line2)">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="font-size:26px">📱</div>
                    <div style="flex:1"><b style="font-size:14px">{{ t('home.noScannerNeeded') }}</b></div>
                </div>
                <div class="btnrow" style="margin-top:12px">
                    <Link :href="route('app.pos')" class="btn" style="flex:1">{{ t('home.sellButton') }}</Link>
                </div>
            </div>

            <div class="card qa-summary-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <b style="font-size:14px">{{ t('home.salesSummary') }}</b>
                    <span style="font-size:11px;color:var(--mut)">{{ productCount }} {{ t('home.products') }} • {{ customerCount }} {{ t('home.customers') }}</span>
                </div>
                <div style="display:flex;gap:10px">
                    <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                        <div style="font-size:10.5px;color:var(--mut)">{{ t('home.thisWeek') }}</div>
                        <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(weekSale) }}</div>
                    </div>
                    <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                        <div style="font-size:10.5px;color:var(--mut)">{{ t('home.thisMonth') }}</div>
                        <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(monthSale) }}</div>
                    </div>
                </div>
            </div>

            <Link v-if="onlineStats" :href="route('app.onlineOrders.index')" class="card qa-summary-card" style="display:block;text-decoration:none;color:inherit">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <b style="font-size:14px">🌐 {{ t('home.onlineOrders') }}</b>
                    <span style="font-size:11px;color:var(--mut)">{{ t('home.viewAll') }} ›</span>
                </div>
                <div style="display:flex;gap:10px">
                    <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                        <div style="font-size:10.5px;color:var(--mut)">{{ t('home.onlinePending') }}</div>
                        <div style="font-size:15px;font-weight:800;margin-top:2px" :style="onlineStats.pendingCount > 0 ? 'color:var(--gold)' : ''">{{ onlineStats.pendingCount }}</div>
                    </div>
                    <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                        <div style="font-size:10.5px;color:var(--mut)">{{ t('home.onlineDeliveredToday') }}</div>
                        <div style="font-size:15px;font-weight:800;margin-top:2px">{{ onlineStats.deliveredTodayCount }} ({{ money(onlineStats.deliveredTodayRevenue) }})</div>
                    </div>
                </div>
            </Link>

            <div class="sechead"><h2>{{ t('home.bestSellers') }}</h2></div>
            <div v-for="(p, i) in bestSellers" :key="p.product_id" class="row">
                <div class="ava">{{ i + 1 }}</div>
                <div class="mid"><b>{{ p.product_name }}</b><span>{{ t('home.unitsSold', { n: p.total_qty }) }}</span></div>
                <div class="end"><b>{{ money(p.total_revenue) }}</b></div>
            </div>
            <div v-if="!bestSellers?.length" class="empty">{{ t('home.noSalesYet') }}</div>

            <div class="sechead"><h2>{{ t('home.recentSales') }}</h2></div>
            <div v-for="s in recentSales" :key="s.id" class="row">
                <div class="ava">🧾</div>
                <div class="mid"><b>{{ s.invoice_no }}</b><span>{{ s.time }} • {{ s.customer?.name || t('home.cashCustomer') }}</span></div>
                <div class="end">
                    <b>{{ money(s.total) }}</b>
                    <span class="pill" :class="payModeClass[s.payment_mode]" style="margin-top:3px">{{ payModeLabel[s.payment_mode] }}</span>
                </div>
            </div>
            <div v-if="!recentSales?.length" class="empty">{{ t('home.noSalesYet') }}</div>

            <div class="sechead"><h2>{{ t('home.topDue') }}</h2></div>
            <template v-if="topDue?.length">
                <div v-for="c in topDue" :key="c.id" class="row">
                    <div class="ava">{{ c.name[0] }}</div>
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }}</span></div>
                    <div class="end"><b class="pill rose">{{ money(c.due) }}</b></div>
                    <button class="btn wa sm" @click="remindWA(c)">{{ t('home.remind') }}</button>
                </div>
            </template>
            <div v-else class="empty">{{ t('home.noDue') }}</div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Everything below is scoped to THIS page only — Khaled's explicit "don't
   touch any other page's design": .stat/.grid2/.card/.sechead/.row are
   shared global classes (Payroll, Loans, Partners, Reports, Stock,
   Restaurant/Tables all reuse them), so the compacting here overrides them
   only inside .home-dash, never their own shared definitions in app.css. */

/* tighter stat tiles — same left-accent-bar card language, less padding,
   a touch less vertical rhythm than the shared default. Plain (non-:deep)
   scoped selectors are correct here — .stat/.grid2 are plain elements
   rendered directly in THIS component's own template, not inside a child
   component, so Vue's scoping attribute already lands on them. */
.home-dash .grid2 { gap: 8px; margin-bottom: 14px; }
.home-dash .stat { padding: 11px 12px; }
.home-dash .stat .k { font-size: 10.5px; }
.home-dash .stat .v { font-size: 19px; margin-top: 4px; }
.home-dash .stat .h { font-size: 10px; margin-top: 1px; }

/* the two "summary card" blocks sitting right under Quick Actions didn't
   need the full 16px .card gap the rest of the page uses between
   unrelated sections — these two visually belong together */
.qa-summary-card { margin-top: 10px; }

/* Quick Actions — bKash-style icon+label shortcut grid, one section per
   sidebar category ("jetugula feature ache sob" — everything, grouped the
   same way the sidebar already groups it, not one flat 30-tile wall). 4
   per row on a phone per the brief; more columns as width allows, never
   fewer than 4. */
.qa-section { margin-top: 16px; }
.qa-section:first-of-type { margin-top: 14px; }
.qa-section-label { font-size: 11px; font-weight: 700; color: var(--mut); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 6px; padding-left: 2px; }
.qa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 2px; }
.qa-item { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 2px; border-radius: 16px; text-decoration: none; color: var(--ink); transition: background .15s ease, transform .1s ease; }
.qa-item:hover { background: var(--panel2, rgba(0, 0, 0, .025)); }
.qa-item:active { transform: scale(.96); }
.qa-icon { width: 46px; height: 46px; border-radius: 15px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
.qa-icon svg { width: 21px; height: 21px; }
.qa-icon.ac-green { background: var(--greenSoft); color: var(--green2); }
.qa-icon.ac-gold { background: var(--goldSoft); color: var(--gold2); }
.qa-icon.ac-sky { background: var(--skySoft); color: var(--sky); }
.qa-icon.ac-purple { background: var(--purpleSoft); color: var(--purple); }
.qa-icon.ac-rose { background: var(--roseSoft); color: var(--rose); }
.qa-label { font-size: 10.5px; font-weight: 650; text-align: center; line-height: 1.25; color: var(--tx); }

@media (min-width: 560px) {
    .qa-grid { grid-template-columns: repeat(6, 1fr); }
}
@media (min-width: 1024px) {
    .qa-grid { grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .qa-icon { width: 50px; height: 50px; }
    .qa-icon svg { width: 23px; height: 23px; }
    .qa-label { font-size: 11.5px; }
}
</style>
