<script setup>
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps({
    businessName: String,
    range: String,
    branches: Array,
    totals: Object,
    revenueTrend: Array,
    recentInvoices: Array,
});

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN');

const payModeLabel = { cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split') };

function setRange(r) {
    router.get(route('app.companyDashboard'), { range: r }, { preserveState: true, preserveScroll: true });
}

const maxTrend = Math.max(1, ...props.revenueTrend.map((m) => m.total));

// Owner-customizable top/bottom margin (mm) for the PDF export — some
// shops print on pre-printed letterhead/pad with its own header/footer
// space, so a fixed margin never fit everyone (Khaled's explicit ask).
const marginTop = ref(10);
const marginBottom = ref(10);

function exportBreakdown(format) {
    let url = route('app.export', 'branch_breakdown') + '?format=' + format;
    if (format === 'pdf') url += '&margin_top=' + marginTop.value + '&margin_bottom=' + marginBottom.value;
    window.open(url, '_blank');
}
</script>

<template>
    <Head title="Business Dashboard" />
    <AppLayout active="companyDashboard">
        <div class="home-dash">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
                <div>
                    <div class="pgttl">🏢 Business Dashboard</div>
                    <div class="pgsub">{{ businessName }} • সব শাখা একসাথে — রিয়েল-টাইম ওভারভিউ</div>
                </div>
                <div class="no-print" style="display:flex;gap:6px;flex-wrap:wrap">
                    <button v-for="r in ['today','week','month','year']" :key="r" class="btn ghost" style="width:auto;padding:4px 12px;font-size:12px" :class="{ active: range === r }" @click="setRange(r)">
                        {{ { today: 'আজ', week: '৭ দিন', month: 'এই মাস', year: 'এই বছর' }[r] }}
                    </button>
                    <button class="btn ghost" style="width:auto;padding:4px 12px;font-size:12px" @click="exportBreakdown('xlsx')">⬇ Excel</button>
                    <button class="btn ghost" style="width:auto;padding:4px 12px;font-size:12px" @click="exportBreakdown('pdf')">⬇ PDF</button>
                    <button class="btn ghost" style="width:auto;padding:4px 12px;font-size:12px" @click="window.print()">🖨 A4 প্রিন্ট</button>
                </div>
            </div>

            <!-- PDF-er top/bottom margin customize — pre-printed letterhead/pad e print korar jonno -->
            <div class="no-print" style="display:flex;gap:10px;align-items:center;margin-top:8px;font-size:12px;color:var(--mut)">
                <span>PDF margin (mm):</span>
                <span>উপরে <input type="number" v-model="marginTop" min="0" max="80" style="width:55px;display:inline-block"></span>
                <span>নিচে <input type="number" v-model="marginBottom" min="0" max="80" style="width:55px;display:inline-block"></span>
            </div>

            <div class="grid2">
                <div class="stat gold">
                    <div class="k">মোট বিক্রি</div>
                    <div class="v">{{ money(totals.sales_total) }}</div>
                    <div class="h">সব শাখা মিলিয়ে</div>
                </div>
                <div class="stat mint">
                    <div class="k">গ্রস প্রফিট</div>
                    <div class="v">{{ money(totals.profit) }}</div>
                    <div class="h">খরচ বাদে না</div>
                </div>
                <div class="stat rose">
                    <div class="k">কাস্টমার বাকি</div>
                    <div class="v">{{ money(totals.customer_due) }}</div>
                    <div class="h">Receivables</div>
                </div>
                <div class="stat sky">
                    <div class="k">সাপ্লায়ার পাওনা</div>
                    <div class="v">{{ money(totals.supplier_payable) }}</div>
                    <div class="h">Payables</div>
                </div>
                <div class="stat gold">
                    <div class="k">স্টক ভ্যালু</div>
                    <div class="v">{{ money(totals.stock_value) }}</div>
                    <div class="h">সব শাখা মিলিয়ে</div>
                </div>
                <div class="stat mint">
                    <div class="k">খরচ</div>
                    <div class="v">{{ money(totals.expenses_total) }}</div>
                    <div class="h">নির্বাচিত সময়ে</div>
                </div>
                <div class="stat sky">
                    <div class="k">কর্মচারী</div>
                    <div class="v">{{ totals.employee_count }}</div>
                    <div class="h">সব শাখা মিলিয়ে</div>
                </div>
                <div class="stat rose">
                    <div class="k">লো স্টক অ্যালার্ট</div>
                    <div class="v">{{ totals.low_stock_count }}</div>
                    <div class="h">রিঅর্ডার দরকার</div>
                </div>
            </div>

            <div class="sechead" style="margin-top:18px"><h2>শাখাভিত্তিক বিক্রি</h2></div>
            <div v-if="!branches.length" class="empty">কোনো শাখা নেই</div>
            <div v-for="b in branches" :key="b.shop_id" class="card" style="margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <b>{{ b.shop_name }}</b>
                    <b>{{ money(b.sales_total) }}</b>
                </div>
                <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin-top:4px">
                    <span>{{ b.sales_count }} বিল</span>
                    <span>প্রফিট: {{ money(b.profit) }}</span>
                    <span>স্টক: {{ money(b.stock_value) }}</span>
                    <span>বাকি: {{ money(b.customer_due) }}</span>
                    <span v-if="b.supplier_payable > 0">পাওনা: {{ money(b.supplier_payable) }}</span>
                    <span v-if="b.employee_count">👥 {{ b.employee_count }}</span>
                    <span v-if="b.low_stock_count" style="color:var(--rose)">⚠ লো স্টক {{ b.low_stock_count }}</span>
                    <span v-if="b.out_of_stock_count" style="color:var(--rose)">❌ স্টক শেষ {{ b.out_of_stock_count }}</span>
                </div>
                <!-- bar relative to the biggest-selling branch, same % logic a
                     leaderboard needs — no charting library in this codebase
                     yet (see Reports.php's own note), so a plain width-based
                     bar is the honest, dependency-free way to show it -->
                <div style="height:6px;background:var(--surface2);border-radius:3px;margin-top:8px;overflow:hidden">
                    <div style="height:100%;background:var(--gold2)" :style="{ width: (branches[0]?.sales_total ? (b.sales_total / branches[0].sales_total * 100) : 0) + '%' }" />
                </div>
            </div>

            <div class="sechead" style="margin-top:18px"><h2>রেভিনিউ ট্রেন্ড (গত ৬ মাস)</h2></div>
            <div class="card" style="display:flex;align-items:flex-end;gap:8px;height:140px;padding:14px">
                <div v-for="m in revenueTrend" :key="m.label" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end">
                    <div style="font-size:11px;color:var(--mut)">{{ m.total > 0 ? money(m.total) : '' }}</div>
                    <div style="width:100%;background:var(--gold2);border-radius:4px 4px 0 0;min-height:2px" :style="{ height: (m.total / maxTrend * 90) + 'px' }" />
                    <div style="font-size:12px;color:var(--mut)">{{ m.label }}</div>
                </div>
            </div>

            <div class="sechead" style="margin-top:18px"><h2>সাম্প্রতিক ইনভয়েস (সব শাখা)</h2></div>
            <div v-if="!recentInvoices.length" class="empty">এখনো কোনো বিক্রি নেই</div>
            <div v-for="inv in recentInvoices" :key="inv.id" class="row">
                <div class="ava">🧾</div>
                <div class="mid">
                    <b>{{ inv.invoice_no }}</b><span style="color:var(--gold2)">{{ inv.branch }}</span>
                    <span>{{ inv.date }} • {{ inv.customer || 'ওয়াক-ইন' }}</span>
                </div>
                <div class="end">
                    <div>{{ money(inv.total) }}</div>
                    <span class="pill" :class="payModeLabel[inv.payment_mode] ? '' : ''">{{ payModeLabel[inv.payment_mode] || inv.payment_mode }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
@media print {
    @page { size: A4; margin: 14mm; }
}
</style>
