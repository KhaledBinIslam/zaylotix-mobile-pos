<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps({
    shop: Object,
    todaySale: Number,
    todayProfit: Number,
    billsToday: Number,
    weekSale: Number,
    weekProfit: Number,
    monthSale: Number,
    monthProfit: Number,
    totalDue: Number,
    dueCustomerCount: Number,
    lowStockCount: Number,
    outOfStockCount: Number,
    productCount: Number,
    customerCount: Number,
    recentSales: Array,
    topDue: Array,
    bestSellers: Array,
});

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function remindWA(customer) {
    const num = '88' + (customer.phone || '').replace(/\D/g, '').replace(/^88/, '');
    const msg = `বিসমিল্লাহ। ${customer.name},\n\n*${props.shop?.name}* এ আপনার বাকি: *${money(customer.due)}*\n\nসুবিধামত পরিশোধ করলে খুশি হবো। ধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(msg), '_blank');
}

const payModeLabel = { cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split') };
const payModeClass = { cash: 'mint', bkash: 'gold', nagad: 'gold', credit: 'rose', split: 'sky' };
</script>

<template>
    <Head :title="t('nav.home')" />
    <AppLayout active="home">
        <div class="pgttl">{{ t('home.greeting') }}</div>
        <div class="pgsub">{{ shop?.name }}</div>

        <div class="grid2">
            <div class="stat gold">
                <div class="k">{{ t('home.todaySale') }}</div>
                <div class="v">{{ money(todaySale) }}</div>
                <div class="h">{{ billsToday }} {{ t('home.bills') }}</div>
            </div>
            <div class="stat mint">
                <div class="k">{{ t('home.todayProfit') }}</div>
                <div class="v">{{ money(todayProfit) }}</div>
                <div class="h">{{ todaySale ? Math.round((todayProfit / todaySale) * 100) : 0 }}% {{ t('home.margin') }}</div>
            </div>
            <div class="stat rose">
                <div class="k">{{ t('home.totalDue') }}</div>
                <div class="v">{{ money(totalDue) }}</div>
                <div class="h">{{ dueCustomerCount }} {{ t('home.customers') }}</div>
            </div>
            <div class="stat sky">
                <div class="k">{{ t('home.lowStock') }}</div>
                <div class="v">{{ lowStockCount }}</div>
                <div class="h">{{ t('home.products') }}</div>
            </div>
        </div>

        <div class="card" style="margin-top:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <b style="font-size:14px">{{ t('home.salesSummary') }}</b>
                <span style="font-size:11px;color:var(--mut)">{{ productCount }} {{ t('home.products') }} • {{ customerCount }} {{ t('home.customers') }}</span>
            </div>
            <div style="display:flex;gap:10px">
                <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                    <div style="font-size:10.5px;color:var(--mut)">{{ t('home.thisWeek') }}</div>
                    <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(weekSale) }}</div>
                    <div style="font-size:10px;color:var(--mint,#1FA463)">{{ t('home.profit') }} {{ money(weekProfit) }}</div>
                </div>
                <div style="flex:1;text-align:center;padding:8px 4px;border-radius:12px;background:var(--panel2,rgba(0,0,0,.02))">
                    <div style="font-size:10.5px;color:var(--mut)">{{ t('home.thisMonth') }}</div>
                    <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(monthSale) }}</div>
                    <div style="font-size:10px;color:var(--mint,#1FA463)">{{ t('home.profit') }} {{ money(monthProfit) }}</div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px;background:linear-gradient(135deg,var(--goldSoft),var(--greenSoft));border-color:var(--line2)">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="font-size:26px">📱</div>
                <div style="flex:1"><b style="font-size:14px">{{ t('home.noScannerNeeded') }}</b></div>
            </div>
            <div class="btnrow" style="margin-top:12px">
                <Link :href="route('app.pos')" class="btn" style="flex:1">{{ t('home.sellButton') }}</Link>
            </div>
        </div>

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
    </AppLayout>
</template>
