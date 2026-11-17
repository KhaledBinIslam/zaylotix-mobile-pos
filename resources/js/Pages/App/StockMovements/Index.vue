<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    movements: Array,
    filters: Object,
    totals: Object,
});

const product = ref(props.filters.product || '');
const type = ref(props.filters.type || 'all');

const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

function applyFilters() {
    router.get(route('app.stockMovements'), { product: product.value, type: type.value }, { preserveState: true, preserveScroll: true });
}

const typeBadge = { purchase: 'mint', sale: 'rose', return: 'sky', damage: 'rose', transfer: 'gold' };
</script>

<template>
    <Head title="Stock Movement Register" />
    <AppLayout active="stockMovements">
        <div class="pgttl">🔁 স্টক মুভমেন্ট রেজিস্টার</div>
        <div class="pgsub">সব শাখা • প্রতিটা ইউনিট ঢুকেছে/বেরিয়েছে — কোন প্রোডাক্ট, কোন শাখা, কখন, কত, কেন</div>

        <div class="grid2" style="margin-top:12px">
            <div class="stat mint">
                <div class="k">ইউনিট ইন</div>
                <div class="v">{{ qty(totals.in) }}</div>
                <div class="h">ক্রয়, ট্রান্সফার-ইন, ফেরত</div>
            </div>
            <div class="stat rose">
                <div class="k">ইউনিট আউট</div>
                <div class="v">{{ qty(totals.out) }}</div>
                <div class="h">বিক্রয়, ট্রান্সফার-আউট, ক্ষতি</div>
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
            <input v-model="product" @keyup.enter="applyFilters" placeholder="প্রোডাক্ট নাম খুঁজুন..." style="flex:1;min-width:160px">
            <select v-model="type" @change="applyFilters" style="width:auto">
                <option value="all">সব ধরন</option>
                <option value="purchase">ক্রয়</option>
                <option value="sale">বিক্রয়</option>
                <option value="return">ফেরত</option>
                <option value="damage">ক্ষতি</option>
                <option value="transfer">ট্রান্সফার</option>
            </select>
            <button class="btn" style="width:auto;padding:0 18px" @click="applyFilters">খুঁজুন</button>
        </div>

        <div v-if="!movements.length" class="empty" style="margin-top:16px">কোনো মুভমেন্ট পাওয়া যায়নি</div>
        <div v-for="(m, idx) in movements" :key="idx" class="row">
            <div class="ava">{{ m.qty_in > 0 ? '⬆' : '⬇' }}</div>
            <div class="mid">
                <b>{{ m.product_name }}</b><span class="pill" :class="typeBadge[m.type]">{{ m.type_label }}</span>
                <span>{{ m.date }} • {{ m.branch }}{{ m.ref ? ' • ' + m.ref : '' }}{{ m.note ? ' • ' + m.note : '' }}</span>
            </div>
            <div class="end">
                <div v-if="m.qty_in > 0" style="color:var(--mint)">+{{ qty(m.qty_in) }}</div>
                <div v-if="m.qty_out > 0" style="color:var(--rose)">−{{ qty(m.qty_out) }}</div>
            </div>
        </div>
    </AppLayout>
</template>
