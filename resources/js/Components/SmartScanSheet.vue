<script setup>
/**
 * Camera-based product capture: scan a barcode (checks the shop's own
 * catalog immediately, before the owner types anything else — see
 * ProductController::barcodeLookup) or photograph a label and run OCR on
 * it client-side (Tesseract.js — no server call, no API key, no cost; a
 * failure here is just a JS error inside this sheet and can never touch
 * checkout or anything else in the app).
 *
 * Every OCR result lands on an editable review screen — @detected only
 * fires once the owner explicitly taps "ফর্মে যুক্ত করুন", and even then
 * it only hands values back to the caller's own form fields, never saves
 * anything itself. A barcode match against an existing product is reported
 * via @barcode-found instead of @detected, so the caller can point at that
 * product rather than silently prefilling a duplicate.
 */
import { ref } from 'vue';
import Sheet from './Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { parseProductText } from '@/support/smartScanParser';

const props = defineProps({ modelValue: Boolean });
const emit = defineEmits(['update:modelValue', 'detected', 'barcode-found']);
const { t } = useI18n();
const { toast } = useToast();

const mode = ref('choose'); // choose | barcode | ocr-processing | ocr-review
let html5Qrcode = null;

function close() {
    stopBarcodeScanner();
    mode.value = 'choose';
    emit('update:modelValue', false);
}

async function startBarcodeScan() {
    mode.value = 'barcode';
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('smart-scan-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            async (decodedText) => {
                if (navigator.vibrate) navigator.vibrate(40);
                await stopBarcodeScanner();
                await checkBarcode(decodedText.trim());
            },
            () => {},
        );
    } catch (e) {
        toast('❌ ' + t('pos.cameraNotFound'));
        mode.value = 'choose';
    }
}
async function stopBarcodeScanner() {
    if (html5Qrcode) {
        try { await html5Qrcode.stop(); html5Qrcode.clear(); } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}
async function checkBarcode(barcode) {
    try {
        const res = await fetch(route('app.products.barcodeLookup', barcode), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (data.exists) {
            emit('barcode-found', { barcode, product: data.product });
        } else {
            emit('detected', { barcode });
        }
    } catch (e) {
        emit('detected', { barcode });
    } finally {
        close();
    }
}

// --- OCR ---
const ocrProgress = ref(0);
const rawText = ref('');
const guess = ref({ name: '', generic_name: '', company: '', price: '', batch_no: '', expiry_date: '', barcode: '' });

async function runOcr(e) {
    const file = e.target.files[0];
    e.target.value = ''; // same file picked twice in a row must still fire @change
    if (!file) return;

    mode.value = 'ocr-processing';
    ocrProgress.value = 0;
    try {
        const Tesseract = await import('tesseract.js');
        const result = await Tesseract.recognize(file, 'eng+ben', {
            logger: (m) => { if (m.status === 'recognizing text') ocrProgress.value = Math.round(m.progress * 100); },
        });
        rawText.value = result.data.text || '';
        guess.value = parseProductText(rawText.value);
        mode.value = 'ocr-review';
    } catch (err) {
        toast('❌ ' + t('smartScan.ocrFailed'));
        mode.value = 'choose';
    }
}

function applyOcrGuess() {
    emit('detected', { ...guess.value, raw_text: rawText.value });
    close();
}
</script>

<template>
    <Sheet :model-value="modelValue" :title="t('smartScan.title')" @update:model-value="close">
        <template v-if="mode === 'choose'">
            <p style="font-size:13px;color:var(--mut);margin-bottom:14px">{{ t('smartScan.chooseHint') }}</p>
            <button class="btn" style="margin-bottom:10px" @click="startBarcodeScan">📷 {{ t('smartScan.scanBarcode') }}</button>
            <label class="btn ghost" style="display:block;text-align:center;cursor:pointer">
                🖼️ {{ t('smartScan.scanLabel') }}
                <input type="file" accept="image/*" capture="environment" style="display:none" @change="runOcr">
            </label>
        </template>

        <template v-else-if="mode === 'barcode'">
            <div id="smart-scan-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
            <div style="text-align:center;color:var(--mut);font-size:13px;margin-top:10px">{{ t('smartScan.barcodeHint') }}</div>
            <button class="btn ghost" style="margin-top:14px" @click="close">{{ t('common.cancel') }}</button>
        </template>

        <template v-else-if="mode === 'ocr-processing'">
            <div style="text-align:center;padding:30px 0">
                <div style="font-size:32px">📖</div>
                <div style="margin-top:10px;color:var(--mut)">{{ t('smartScan.processing') }} {{ ocrProgress }}%</div>
            </div>
        </template>

        <template v-else-if="mode === 'ocr-review'">
            <div class="card" style="background:var(--goldSoft);border-color:var(--gold2);margin-bottom:14px;font-size:12px">
                ⚠️ {{ t('smartScan.verifyWarning') }}
            </div>
            <div class="field"><label>{{ t('stock.productName') }}</label><input v-model="guess.name"></div>
            <div class="field"><label>{{ t('stock.genericName') }}</label><input v-model="guess.generic_name"></div>
            <div class="field"><label>{{ t('stock.company') }}</label><input v-model="guess.company"></div>
            <div class="f2">
                <div class="field"><label>{{ t('smartScan.priceOrMrp') }}</label><input v-model="guess.price" type="number" step="0.01"></div>
                <div class="field"><label>{{ t('stock.barcode') }}</label><input v-model="guess.barcode"></div>
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('stock.batchNo') }}</label><input v-model="guess.batch_no"></div>
                <div class="field"><label>{{ t('stock.expiryDate') }}</label><input v-model="guess.expiry_date" type="date"></div>
            </div>
            <details style="margin:10px 0;font-size:12px;color:var(--mut)">
                <summary style="cursor:pointer">{{ t('smartScan.rawTextToggle') }}</summary>
                <pre style="white-space:pre-wrap;margin-top:6px;font-family:inherit">{{ rawText }}</pre>
            </details>
            <button class="btn" @click="applyOcrGuess">{{ t('smartScan.applyButton') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="close">{{ t('common.cancel') }}</button>
        </template>
    </Sheet>
</template>
