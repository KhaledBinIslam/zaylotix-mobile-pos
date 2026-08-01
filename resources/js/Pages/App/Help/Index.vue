<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { openWhatsAppHelp } from '@/support/help';

const { t } = useI18n();
const page = usePage();
const shop = computed(() => page.props.shop);

const FAQ_KEYS = ['1', '2', '3', '4', '5', '6'];
const openIndex = ref(0);
function toggle(i) {
    openIndex.value = openIndex.value === i ? -1 : i;
}
function contactSupport() {
    openWhatsAppHelp(shop.value?.name, t('nav.help'));
}
</script>

<template>
    <Head :title="t('help.title')" />
    <AppLayout active="help">
        <div class="pgttl">{{ t('help.title') }}</div>
        <div class="pgsub">{{ t('help.subtitle') }}</div>

        <button class="btn" style="background:#25D366;box-shadow:0 4px 14px rgba(37,211,102,.3);margin-bottom:16px" @click="contactSupport">
            {{ t('help.whatsappCta') }}
        </button>

        <div v-for="i in FAQ_KEYS" :key="i" class="card" style="margin-bottom:10px;cursor:pointer" @click="toggle(i)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                <b style="font-size:14.5px">{{ t('help.q' + i) }}</b>
                <span style="color:var(--mut);font-size:18px;flex:0 0 auto">{{ openIndex === i ? '−' : '+' }}</span>
            </div>
            <div v-if="openIndex === i" style="margin-top:10px;font-size:13.5px;color:var(--mut);line-height:1.6">{{ t('help.a' + i) }}</div>
        </div>
    </AppLayout>
</template>
