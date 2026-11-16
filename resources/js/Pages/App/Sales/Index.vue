<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ sales: Object, q: String, mine: Boolean, from: String, to: String, category_id: Number, categories: Array });

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const search = ref(props.q || '');
const fromDate = ref(props.from || '');
const toDate = ref(props.to || '');
const categoryId = ref(props.category_id || '');

function currentFilters() {
    return {
        q: search.value, mine: props.mine ? 1 : undefined,
        from: fromDate.value || undefined, to: toDate.value || undefined,
        category_id: categoryId.value || undefined,
    };
}

function applySearch() {
    router.get(route('app.sales'), currentFilters(), { preserveState: true });
}

function applyDateFilter() {
    router.get(route('app.sales'), currentFilters(), { preserveState: true });
}

function toggleMine() {
    router.get(route('app.sales'), { ...currentFilters(), mine: props.mine ? undefined : 1 }, { preserveState: true });
}

function clearFilters() {
    search.value = ''; fromDate.value = ''; toDate.value = ''; categoryId.value = '';
    router.get(route('app.sales'), { mine: props.mine ? 1 : undefined }, { preserveState: true });
}

const hasActiveFilters = computed(() => !!(search.value || fromDate.value || toDate.value || categoryId.value));

const payModeLabel = computed(() => ({ cash: t('pay.cash'), bkash: t('pay.bkash'), nagad: t('pay.nagad'), credit: t('pay.credit'), split: t('pay.split') }));
const payModeClass = { cash: 'mint', bkash: 'gold', nagad: 'gold', credit: 'rose', split: 'sky' };
</script>

<template>
    <Head :title="t('nav.salesHistory')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('nav.salesHistory') }}</div>
        <div class="pgsub">{{ t('sales.subtitle') }}</div>

        <div style="display:flex;gap:8px;margin-bottom:10px">
            <input v-model="search" :placeholder="t('sales.searchPlaceholder')" style="flex:1" @keyup.enter="applySearch">
            <button class="btn sm" style="width:auto;padding:0 16px" @click="applySearch">{{ t('sales.searchButton') }}</button>
        </div>

        <div class="f2" style="margin-bottom:10px">
            <div class="field"><label>{{ t('sales.fromDate') }}</label><input type="date" v-model="fromDate" @change="applyDateFilter"></div>
            <div class="field"><label>{{ t('sales.toDate') }}</label><input type="date" v-model="toDate" @change="applyDateFilter"></div>
        </div>
        <div class="field" style="margin-bottom:10px" v-if="categories?.length">
            <label>{{ t('sales.categoryFilter') }}</label>
            <select v-model="categoryId" @change="applyDateFilter">
                <option value="">{{ t('sales.allCategories') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.emoji }} {{ c.name }}</option>
            </select>
        </div>
        <button v-if="hasActiveFilters" class="btn ghost sm" style="width:100%;margin-bottom:10px" @click="clearFilters">✕ {{ t('sales.clearFilters') }}</button>

        <button class="btn sm" :class="{ ghost: !mine }" style="width:100%;margin-bottom:14px" @click="toggleMine">
            {{ mine ? t('sales.mineOn') : t('sales.mineOff') }}
        </button>

        <div v-for="s in sales.data" :key="s.id" class="row" :class="{ voided: s.voided_at }" @click="router.visit(route('app.sales.show', s.id))">
            <div class="ava">🧾</div>
            <div class="mid">
                <b>{{ s.invoice_no }}</b>
                <span>{{ s.date }} • {{ s.time }} • {{ s.customer?.name || t('home.cashCustomer') }}{{ s.user ? ' • ' + s.user.name : '' }}</span>
            </div>
            <div class="end">
                <b :style="s.voided_at ? 'text-decoration:line-through;color:var(--mut)' : ''">{{ money(s.total) }}</b>
                <span v-if="s.voided_at" class="pill rose" style="margin-top:3px">🚫 {{ t('sales.voided') }}</span>
                <span v-else class="pill" :class="payModeClass[s.payment_mode]" style="margin-top:3px">{{ payModeLabel[s.payment_mode] }}</span>
            </div>
        </div>
        <div v-if="!sales.data.length" class="empty"><div class="big">🧾</div>{{ t('sales.notFound') }}</div>
        <Pagination :links="sales.links" />
    </AppLayout>
</template>
