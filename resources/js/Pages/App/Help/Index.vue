<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { openWhatsAppHelp } from '@/support/help';

const props = defineProps({ faqs: { type: Array, default: () => [] } });
const { t, lang } = useI18n();
const page = usePage();
const shop = computed(() => page.props.shop);

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

        <div v-for="(f, i) in faqs" :key="i" class="card" style="margin-bottom:10px;cursor:pointer" @click="toggle(i)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                <b style="font-size:14.5px">{{ lang === 'en' ? f.question_en : f.question_bn }}</b>
                <span style="color:var(--mut);font-size:18px;flex:0 0 auto">{{ openIndex === i ? '−' : '+' }}</span>
            </div>
            <div v-if="openIndex === i" style="margin-top:10px;font-size:13.5px;color:var(--mut);line-height:1.6;white-space:pre-wrap">{{ lang === 'en' ? f.answer_en : f.answer_bn }}</div>
        </div>
        <div v-if="!faqs.length" class="empty"><div class="big">❓</div>{{ t('help.noFaqs') }}</div>
    </AppLayout>
</template>
