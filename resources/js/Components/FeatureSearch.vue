<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { useNavItems } from '@/composables/useNavItems';
import { ICONS } from '@/support/navIcons';
import { flattenSearchable, searchItems } from '@/support/featureSearch';
import { buildExtraSearchItems } from '@/support/moreMenuExtraItems';

// A single search box, dropped into Home.vue and More.vue, that finds ANY
// feature/menu item this shop can actually reach right now — Khaled's
// explicit ask: "kono feature/option easily search kore paowa jai moto".
// Fully dynamic per shop: useNavItems()/buildExtraSearchItems() already
// gate every entry by the exact same hasPerm()/hasFeature() checks the
// sidebar and More.vue itself use, so a shop missing a feature simply
// never has that item to find — nothing hardcoded per shop.
const { t } = useI18n();
const page = usePage();
const { navGroups, hasPerm, hasFeature, isOwner } = useNavItems();
const branches = computed(() => page.props.branches);

const allItems = computed(() => flattenSearchable(
    navGroups.value,
    buildExtraSearchItems({ hasPerm, isOwner: isOwner.value, hasFeature, branches: branches.value, t }),
));

const q = ref('');
const open = ref(false);
const results = computed(() => searchItems(allItems.value, q.value).slice(0, 10));

function go(item) {
    q.value = '';
    open.value = false;
    router.visit(item.href);
}
function onBlur() {
    // short delay so a click/tap on a result row still registers before
    // the dropdown unmounts — same pattern used elsewhere in this app for
    // a blur-closable dropdown
    setTimeout(() => { open.value = false; }, 150);
}
</script>

<template>
    <div class="fsearch">
        <div class="fsearch-box">
            <span class="fsearch-icon">🔍</span>
            <input
                v-model="q"
                type="search"
                :placeholder="t('search.placeholder')"
                @focus="open = true"
                @blur="onBlur"
            >
            <button v-if="q" type="button" class="fsearch-clear" @mousedown.prevent="q = ''">✕</button>
        </div>
        <div v-if="open && q.trim()" class="fsearch-results">
            <button v-for="r in results" :key="r.key" type="button" class="fsearch-row" @mousedown.prevent="go(r)">
                <span class="fsearch-row-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[r.icon]"></svg>
                </span>
                <span class="fsearch-row-text">
                    <b>{{ r.label }}</b>
                    <span>{{ r.groupLabel }}</span>
                </span>
            </button>
            <div v-if="!results.length" class="fsearch-empty">{{ t('search.noResults') }}</div>
        </div>
    </div>
</template>

<style scoped>
.fsearch { position: relative; margin-bottom: 14px; }
.fsearch-box { display: flex; align-items: center; gap: 8px; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 0 12px; }
.fsearch-icon { font-size: 15px; opacity: .6; }
.fsearch-box input { flex: 1; border: none; background: none; padding: 11px 0; font-size: 14px; color: var(--ink); min-width: 0; }
.fsearch-box input:focus { outline: none; }
.fsearch-clear { border: none; background: none; color: var(--mut); font-size: 13px; cursor: pointer; padding: 4px 6px; }

.fsearch-results { position: absolute; left: 0; right: 0; top: calc(100% + 6px); z-index: 30; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 10px 30px rgba(0, 0, 0, .12); max-height: 60vh; overflow-y: auto; padding: 6px; }
.fsearch-row { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; border: none; background: none; cursor: pointer; padding: 9px 8px; border-radius: 10px; color: var(--ink); }
.fsearch-row:hover, .fsearch-row:active { background: var(--panel2, rgba(0, 0, 0, .04)); }
.fsearch-row-icon { width: 32px; height: 32px; flex: 0 0 auto; border-radius: 10px; background: var(--goldSoft); color: var(--gold2); display: flex; align-items: center; justify-content: center; }
.fsearch-row-icon svg { width: 16px; height: 16px; }
.fsearch-row-text { display: flex; flex-direction: column; min-width: 0; }
.fsearch-row-text b { font-size: 13.5px; font-weight: 650; }
.fsearch-row-text span { font-size: 11px; color: var(--mut); }
.fsearch-empty { padding: 14px 10px; text-align: center; font-size: 13px; color: var(--mut); }
</style>
