<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

/**
 * "ডিজিটাল ক্যাটালগ লিঙ্ক" — see docs/FIX_PLAN.md's design note. This page
 * is purely the owner's control panel (the link + which products are
 * visible); the public-facing page itself is Public/Catalog.vue, reached
 * with no login via CatalogController.
 */
const props = defineProps({ catalogUrl: String, products: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const visibleCount = computed(() => props.products.filter((p) => p.show_in_catalog).length);

const q = ref('');
const filtered = computed(() => {
    const query = q.value.trim().toLowerCase();
    if (!query) return props.products;
    return props.products.filter((p) => p.name.toLowerCase().includes(query));
});

function toggle(p) {
    router.patch(route('app.products.catalogVisibility', p.id), { show_in_catalog: !p.show_in_catalog }, { preserveScroll: true });
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.catalogUrl);
        toast('✅ ' + t('catalog.linkCopied'));
    } catch (e) {
        toast('❌ ' + t('catalog.linkCopyFailed'));
    }
}

function shareToWhatsapp() {
    window.open('https://wa.me/?text=' + encodeURIComponent(props.catalogUrl), '_blank');
}
</script>

<template>
    <Head :title="t('nav.catalog')" />
    <AppLayout active="catalog">
        <div class="pgttl">{{ t('nav.catalog') }}</div>
        <div class="pgsub">{{ t('catalog.subtitle') }}</div>
        <HowToHint screen-key="catalog" />

        <div class="card" style="margin-bottom:16px">
            <div style="font-size:12px;color:var(--mut);margin-bottom:6px">{{ t('catalog.yourLink') }}</div>
            <div style="font-size:13.5px;word-break:break-all;color:var(--purple);font-weight:700;margin-bottom:12px">{{ catalogUrl }}</div>
            <div class="btnrow">
                <button class="btn ghost sm" style="flex:1" @click="copyLink">📋 {{ t('catalog.copyLink') }}</button>
                <button class="btn wa sm" style="flex:1" @click="shareToWhatsapp">💬 {{ t('catalog.shareLink') }}</button>
            </div>
        </div>

        <div class="sechead"><h2>{{ t('catalog.pickProducts') }}</h2></div>
        <div style="font-size:12.5px;color:var(--mut);margin-bottom:10px">{{ t('catalog.pickProductsHint', { n: visibleCount }) }}</div>

        <div class="field" style="margin-bottom:12px">
            <input v-model="q" :placeholder="t('stock.searchPlaceholder')">
        </div>

        <div v-for="p in filtered" :key="p.id" class="row" @click="toggle(p)">
            <div class="ava">{{ p.emoji || '📦' }}</div>
            <div class="mid"><b>{{ p.name }}</b><span>{{ money(p.price) }}</span></div>
            <div class="end">
                <span class="pill" :class="p.show_in_catalog ? 'mint' : ''">{{ p.show_in_catalog ? t('catalog.visible') : t('catalog.hidden') }}</span>
            </div>
        </div>

        <div v-if="!filtered.length" class="empty"><div class="big">🔍</div>{{ t('stock.noProducts') }}</div>
    </AppLayout>
</template>
