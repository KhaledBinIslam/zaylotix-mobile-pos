<script setup>
import { watch, onBeforeUnmount } from 'vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

// `wide` — opt-in, only for a sheet whose content genuinely benefits from
// desktop/tablet room (checkout being the main case: Khaled's explicit
// request was a wide, side-by-side, non-scrolling modal on a bigger
// screen instead of the same narrow bottom-drawer meant for a phone).
// Every other sheet (settings, pickers, short forms) is untouched — this
// is scoped per-usage, not a global behavior change to #sheet.
const props = defineProps({ modelValue: Boolean, title: String, subtitle: String, wide: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue']);

// A sheet never gets its own URL/history entry - it's just local component
// state toggling over the same page. That meant Android's hardware back
// button (and the in-app "ফিরে যান" link, which also just calls
// history.back()) had nothing to "undo" for an open sheet: it skipped
// straight past closing it and navigated a whole page back instead,
// landing somewhere the cashier didn't expect (reported: back button
// "takes you to the wrong screen", and on some screens looking like there
// was no working back button at all). Standard fix for a modal in an SPA:
// push one throwaway history entry while the sheet is open so back has
// something to consume first, and pop it back off ourselves if the sheet
// gets closed any other way (✕/scrim/save) so a second back press isn't
// needed to actually leave the page.
let closedByPopstate = false;
function onPopState() {
    closedByPopstate = true;
    emit('update:modelValue', false);
}
watch(() => props.modelValue, (open) => {
    if (open) {
        // spread the current (Inertia-owned) state first so its own
        // popstate handler still finds what it expects there - we're only
        // adding a marker on top, never replacing what Inertia already put
        history.pushState({ ...history.state, zaylotixSheet: true }, '');
        window.addEventListener('popstate', onPopState);
    } else {
        window.removeEventListener('popstate', onPopState);
        if (!closedByPopstate && history.state?.zaylotixSheet) history.back();
        closedByPopstate = false;
    }
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>

<template>
    <Teleport to="body">
        <div v-if="modelValue" id="scrim" style="display: block" @click="$emit('update:modelValue', false)" />
        <div v-if="modelValue" id="sheet" :class="{ wide }" style="display: block">
            <div class="grab" />
            <!-- reported with screenshots: closing relied entirely on tapping
                 the dimmed backdrop, an invisible affordance nobody could be
                 expected to discover on their own. The backdrop tap still
                 works, but this is now the primary, always-visible way out
                 of any sheet in the app, not just the ones with a title. -->
            <button type="button" class="sheet-close" :aria-label="t('common.close')" @click="$emit('update:modelValue', false)">✕</button>
            <div v-if="title" class="shttl">{{ title }}</div>
            <div v-if="subtitle" class="shsub">{{ subtitle }}</div>
            <slot />
        </div>
    </Teleport>
</template>
