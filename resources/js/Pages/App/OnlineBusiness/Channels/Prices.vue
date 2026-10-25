<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { reportClientError } from '@/support/reportClientError';

const props = defineProps({ channel: Object, products: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const q = ref('');
const filtered = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return needle ? props.products.filter((p) => p.name.toLowerCase().includes(needle)) : props.products;
});

// edited locally first, saved on blur/Enter — avoids a request per
// keystroke the way a plain v-model-bound PATCH-on-input would
const drafts = ref(Object.fromEntries(props.products.map((p) => [p.id, p.override_price ?? ''])));

function saveOverride(p) {
    const raw = drafts.value[p.id];
    const price = raw === '' || raw === null ? null : Number(raw);
    fetch(route('app.channels.setPrice', [props.channel.id, p.id]), {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json', Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
        },
        body: JSON.stringify({ price }),
    })
        .then((r) => { if (!r.ok) throw new Error(`setPrice responded ${r.status}`); })
        .then(() => toast('✅ ' + t('channel.priceSaved')))
        .catch((e) => { reportClientError(e, 'channel price override'); toast('❌ ' + t('channel.priceSaveFailed')); });
}
</script>

<template>
    <Head :title="channel.name" />
    <AppLayout active="channels">
        <div class="pgttl">{{ channel.name }}</div>
        <div class="pgsub">{{ t('channel.pricesSubtitle') }}</div>

        <Link :href="route('app.channels.index')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← {{ t('common.back') }}</Link>

        <input v-model="q" :placeholder="t('channel.searchProduct')" style="margin-bottom:12px">

        <div v-for="p in filtered" :key="p.id" class="card" style="margin-bottom:8px">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="flex:1">
                    <b>{{ p.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ t('channel.defaultPrice') }}: {{ money(p.default_price) }}</div>
                </div>
                <input
                    v-model="drafts[p.id]" type="number" step="0.01" min="0" :placeholder="t('channel.noOverride')"
                    style="width:110px" @blur="saveOverride(p)" @keyup.enter="saveOverride(p)"
                >
            </div>
        </div>

        <div v-if="!filtered.length" class="empty"><div class="big">📦</div>{{ t('channel.noProducts') }}</div>
    </AppLayout>
</template>
