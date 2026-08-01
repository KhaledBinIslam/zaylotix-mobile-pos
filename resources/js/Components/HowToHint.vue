<script setup>
import { ref } from 'vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ text: { type: String, required: true } });
const { t } = useI18n();
// shown open by default — a collapsed hint a tech-nervous owner has to
// know to tap first isn't really a guide, it's a secret; tapping the
// header still lets them collapse it out of the way once they know it by heart
const open = ref(true);
</script>

<template>
    <div class="howto" @click="open = !open">
        <div class="howto-head">📖 {{ t('common.howToDoThis') }} <span>{{ open ? '−' : '+' }}</span></div>
        <div v-if="open" class="howto-body">{{ text }}</div>
    </div>
</template>
