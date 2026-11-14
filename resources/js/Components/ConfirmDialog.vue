<script setup>
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { confirmState } from '@/composables/useConfirm';

/**
 * One shared in-app replacement for every `confirm(message)`/
 * `window.confirm(message)` call across the shop-facing app — mounted
 * once in AppLayout.vue, driven by useConfirm.js's confirmAsync().
 *
 * Why this exists at all: a native confirm() dialog is known to get
 * silently auto-dismissed — resolves exactly as if the user tapped
 * Cancel, with nothing on screen to show for it — inside some Android
 * WebView/PWA "add to home screen" contexts, which is precisely how this
 * app runs on a shop's own device. Traced live on a clothing demo shop
 * (shop 387): checkout's own one-tap-sale confirm() meant 0 sales ever
 * went through, "বিল আসছে না" with no visible error at all. An in-app
 * Sheet can never be suppressed that way.
 */
const { t } = useI18n();

function answer(value) {
    confirmState.value.resolve?.(value);
    confirmState.value = { open: false, message: '', resolve: null };
}
</script>

<template>
    <Sheet :model-value="confirmState.open" @update:model-value="answer(false)">
        <p style="font-size:15px;margin-bottom:18px;white-space:pre-line">{{ confirmState.message }}</p>
        <button class="btn" @click="answer(true)">{{ t('common.yes') }}</button>
        <button class="btn ghost" style="margin-top:10px" @click="answer(false)">{{ t('common.cancel') }}</button>
    </Sheet>
</template>
