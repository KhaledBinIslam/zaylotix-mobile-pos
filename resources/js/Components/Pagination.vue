<script setup>
import { router } from '@inertiajs/vue3';

// `links` is Laravel's LengthAwarePaginator::links() shape:
// [{url, label, active}, ...] — first/last entries are the « Prev / Next »
// arrows (label contains "pagination.previous"/"pagination.next" HTML
// entities), everything between is a page number. `url` is null on a
// disabled arrow (already on page 1, etc).
const props = defineProps({ links: { type: Array, default: () => [] } });

function go(url) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function label(l) {
    if (l.label.includes('Previous')) return '‹';
    if (l.label.includes('Next')) return '›';
    return l.label;
}
</script>

<template>
    <div v-if="links.length > 3" style="display:flex;gap:6px;overflow-x:auto;padding:4px 0;margin:10px 0">
        <button
            v-for="(l, i) in links" :key="i"
            class="btn sm ghost" style="width:auto;padding:0 12px;flex:0 0 auto"
            :class="{ on: l.active }"
            :style="l.active ? 'background:var(--gold);color:#111;border-color:var(--gold)' : (!l.url ? 'opacity:.4' : '')"
            :disabled="!l.url"
            @click="go(l.url)"
        >{{ label(l) }}</button>
    </div>
</template>
