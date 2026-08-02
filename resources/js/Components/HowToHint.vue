<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';

// screenKey looks up admin-managed text (see ScreenGuide/Admin/ScreenGuides) --
// text stays as a plain override for the rare case a page wants to pass its
// own hardcoded string directly instead. If neither resolves to anything,
// this renders nothing at all -- an unset guide is not an error, it's the
// admin's choice not to show one there yet.
const props = defineProps({ text: { type: String, default: '' }, screenKey: { type: String, default: '' } });
const { t, lang } = useI18n();
const page = usePage();

const resolvedText = computed(() => {
    if (props.text) return props.text;
    const guide = props.screenKey ? page.props.guides?.[props.screenKey] : null;
    if (!guide) return '';
    return (lang.value === 'en' ? guide.text_en : guide.text_bn) || '';
});

// shown open by default — a collapsed hint a tech-nervous owner has to
// know to tap first isn't really a guide, it's a secret; tapping the
// header still lets them collapse it out of the way once they know it by heart
const open = ref(true);
</script>

<template>
    <div v-if="resolvedText" class="howto" @click="open = !open">
        <div class="howto-head">📖 {{ t('common.howToDoThis') }} <span>{{ open ? '−' : '+' }}</span></div>
        <div v-if="open" class="howto-body">{{ resolvedText }}</div>
    </div>
</template>
