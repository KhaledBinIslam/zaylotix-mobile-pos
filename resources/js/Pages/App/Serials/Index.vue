<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ q: String, results: Array });
const { t } = useI18n();

const search = ref(props.q || '');
function applySearch() {
    router.get(route('app.serials.index'), { q: search.value }, { preserveState: true });
}
</script>

<template>
    <Head :title="t('serial.title')" />
    <AppLayout active="serials">
        <div class="pgttl">{{ t('serial.title') }}</div>
        <div class="pgsub">{{ t('serial.subtitle') }}</div>

        <div style="display:flex;gap:8px;margin-bottom:14px">
            <input v-model="search" :placeholder="t('serial.searchPlaceholder')" @keyup.enter="applySearch">
            <button class="btn sm" style="width:auto;padding:0 16px" @click="applySearch">{{ t('sales.searchButton') }}</button>
        </div>

        <div v-for="r in results" :key="r.id" class="row" style="cursor:default">
            <div class="ava">{{ r.status === 'sold' ? '📦' : '🆕' }}</div>
            <div class="mid">
                <b>{{ r.imei }}</b>
                <span>{{ r.product }}</span>
                <span v-if="r.status === 'sold' && r.sale_invoice">{{ t('serial.soldVia') }} {{ r.sale_invoice }} • {{ r.customer || t('home.cashCustomer') }}<template v-if="r.customer_phone"> ({{ r.customer_phone }})</template></span>
                <span v-if="r.warranty_expiry">{{ t('serial.warranty') }} {{ r.warranty_expiry }}</span>
            </div>
            <div class="end">
                <span class="pill" :class="r.status === 'sold' ? 'sky' : 'mint'">{{ r.status === 'sold' ? t('serial.sold') : t('serial.inStock') }}</span>
                <span v-if="r.warranty_expiry" class="pill" :class="r.under_warranty ? 'mint' : 'rose'" style="margin-top:4px">
                    {{ r.under_warranty ? '✓ ' + t('serial.underWarranty') : t('serial.expired') }}
                </span>
            </div>
        </div>
        <div v-if="q && !results.length" class="empty"><div class="big">🔍</div>{{ t('serial.notFound') }}</div>
        <div v-if="!q" class="empty"><div class="big">📱</div>{{ t('serial.searchHint') }}</div>
    </AppLayout>
</template>
