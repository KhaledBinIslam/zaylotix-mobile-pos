<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ sales: Object, q: String, mine: Boolean });

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const search = ref(props.q || '');

function applySearch() {
    router.get(route('app.sales'), { q: search.value, mine: props.mine ? 1 : undefined }, { preserveState: true });
}

function toggleMine() {
    router.get(route('app.sales'), { q: search.value, mine: props.mine ? undefined : 1 }, { preserveState: true });
}

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
