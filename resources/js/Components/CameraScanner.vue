<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useI18n } from '@/composables/useI18n';
import { scanBoxSize } from '@/support/scanBoxSize';
import { scannerFormats, scannerOptions } from '@/support/scannerConfig';

/**
 * Standalone camera barcode scanner overlay — same html5-qrcode wiring and
 * #scanwrap/.scan-* styling as Pos/Index.vue's own scanner (reused CSS,
 * not duplicated), factored out here so a page OTHER than POS (Online
 * Order's Create.vue) can offer the same recognizable "📷 tap to scan"
 * affordance instead of a plain, easy-to-miss text field. Deliberately a
 * fresh component rather than extracting POS's existing one in place —
 * POS's scanner is live, working, and just-fixed; this avoids any risk of
 * regressing it while adding the same capability elsewhere.
 */
const emit = defineEmits(['scan', 'close', 'error']);
const { t } = useI18n();

const scanHint = ref('');
let html5Qrcode = null;
let lastScanCode = '';
let lastScanAt = 0;

async function open() {
    scanHint.value = t('pos.scanHintDefault');
    try {
        // Exactly POS's setup (support/scannerConfig.js): the shop's own
        // CODE_128 stickers in the format list and 720p frames. This
        // component had drifted to "every symbology, 1080p" — the two
        // things that file's note names as what made decoding fail — so
        // the Exchange and Online Order scanners opened the camera but
        // never read a label while POS's did (Baby Park, 2026-11-24).
        const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('reader', {
            verbose: false,
            formatsToSupport: scannerFormats(Html5QrcodeSupportedFormats),
        });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            scannerOptions(scanBoxSize),
            onScanSuccess,
            () => {},
        );
    } catch (e) {
        emit('error');
        emit('close');
    }
}

async function close() {
    if (html5Qrcode) {
        try {
            await html5Qrcode.stop();
            html5Qrcode.clear();
        } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
    emit('close');
}

function onScanSuccess(decodedText) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return; // debounce repeat frames
    lastScanCode = decodedText;
    lastScanAt = now;
    if (navigator.vibrate) navigator.vibrate(40);
    // Confirms the camera actually read something, distinct from whatever the
    // caller's lookup decides right after — see Pos/Index.vue's onScanSuccess.
    scanHint.value = '✅ ' + t('pos.scannedCode') + ': ' + decodedText;
    emit('scan', decodedText, (msg) => (scanHint.value = msg));
}

defineExpose({ open, close });
onBeforeUnmount(() => { if (html5Qrcode) html5Qrcode.stop().catch(() => {}); });
</script>

<template>
    <Teleport to="body">
        <div id="scanwrap">
            <div class="scan-top">
                <button class="scan-x" @click="close">✕</button>
                <div style="color:#fff;font-weight:700">{{ t('pos.scanProductTitle') }}</div>
            </div>
            <div id="reader"></div>
            <div class="scan-frame"></div>
            <div class="scan-hint">{{ scanHint }}</div>
            <div class="scan-manual">
                <button class="btn ghost" style="background:rgba(0,0,0,.55);color:#fff;border-color:rgba(255,255,255,.25)" @click="close">
                    {{ t('common.close') }}
                </button>
            </div>
        </div>
    </Teleport>
</template>
