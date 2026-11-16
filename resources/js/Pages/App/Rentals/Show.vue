<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useToast } from '@/composables/useToast';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';
import { matchRentalReturnItem } from '@/support/rentalScan';

const props = defineProps({ rental: Object, rawItems: Array, returns: Array, payments: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

const lineState = ref(
    Object.fromEntries((props.rawItems || []).map((i) => [i.id, { qty: 0, condition: 'resalable', late_fee: 0, damage_charge: 0, checked: false }]))
);

const extraCharge = computed(() => Object.values(lineState.value).reduce((sum, l) => sum + (l.checked ? Number(l.late_fee || 0) + Number(l.damage_charge || 0) : 0), 0));

// --- scan to select which item is being returned — same continuous-scan,
// barcode-first pattern as everywhere else in the app. Matches only
// against THIS rental's own rawItems (never a different rental's product),
// checks the box, and bumps qty by 1 each scan (capped at outstanding_qty).
let lastScanCode = '';
let lastScanAt = 0;
function handleScan(decodedText, onFeedback) {
    const now = Date.now();
    if (decodedText === lastScanCode && now - lastScanAt < 1500) return;
    lastScanCode = decodedText;
    lastScanAt = now;
    if (navigator.vibrate) navigator.vibrate(40);
    const code = decodedText.trim();

    const match = matchRentalReturnItem(props.rawItems, code);
    if (match.status === 'not_found') {
        onFeedback('❌ ' + t('rental.scannedNotInThisRental'));
        return;
    }
    if (match.status === 'fully_returned') {
        onFeedback('❌ ' + match.item.product_name + ' — ' + t('rental.scannedAlreadyFullyReturned'));
        return;
    }
    const item = match.item;
    const line = lineState.value[item.id];
    line.checked = true;
    line.qty = Math.min(item.outstanding_qty, Number(line.qty || 0) + 1);
    onFeedback('✅ ' + item.product_name + (item.variant_label ? ' (' + item.variant_label + ')' : '') + ' × ' + qty(line.qty));
}

useHardwareScanner((code) => handleScan(code, (msg) => toast(msg)), () => props.rental.status !== 'returned');

const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
async function openScanner() {
    scannerOpen.value = true;
    scanHint.value = t('rental.scanReturnHint');
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('rental-return-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            (text) => handleScan(text, (msg) => (scanHint.value = msg)),
            () => {},
        );
    } catch (e) {
        toast('❌ ' + t('rental.cameraNotFound'));
        closeScanner();
    }
}
async function closeScanner() {
    scannerOpen.value = false;
    if (html5Qrcode) {
        try { await html5Qrcode.stop(); html5Qrcode.clear(); } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}
onBeforeUnmount(() => { if (html5Qrcode) html5Qrcode.stop().catch(() => {}); });
const paymentAmount = ref(0);
const paymentMethod = ref('cash');
const submitting = ref(false);
const errorMsg = ref('');

let pendingUuid = null;
function nextUuid() {
    if (!pendingUuid) {
        pendingUuid = (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
    }
    return pendingUuid;
}

async function submitReturn() {
    const lines = props.rawItems
        .filter((i) => lineState.value[i.id]?.checked && Number(lineState.value[i.id]?.qty) > 0)
        .map((i) => ({
            rental_item_id: i.id,
            qty: Number(lineState.value[i.id].qty),
            condition: lineState.value[i.id].condition,
            late_fee: Number(lineState.value[i.id].late_fee || 0),
            damage_charge: Number(lineState.value[i.id].damage_charge || 0),
        }));
    if (!lines.length) { errorMsg.value = t('rental.selectAtLeastOneItem'); return; }

    submitting.value = true;
    errorMsg.value = '';
    try {
        const res = await fetchWithSessionRetry(route('app.rentals.processReturn', props.rental.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: JSON.stringify({ lines, payment_amount: Number(paymentAmount.value || 0), payment_method: paymentMethod.value, client_uuid: nextUuid() }),
        });
        const data = await res.json();
        if (!res.ok) {
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || t('rental.genericError');
            return;
        }
        pendingUuid = null;
        toast(t('rental.returnProcessedToast'));
        window.location.reload();
    } catch (e) {
        errorMsg.value = t('rental.networkError');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="t('nav.rentals') + ' — ' + rental.invoice_no" />
    <AppLayout active="rentals">
        <div class="pgttl">📅 {{ rental.invoice_no }}</div>
        <div class="pgsub">{{ rental.customer?.name }} • {{ rental.rental_date }} → {{ rental.expected_return_date }}</div>

        <div class="card" style="margin-top:10px">
            <div>{{ t('rental.totalRentLabel') }}: <b>{{ money(rental.total_rent_amount) }}</b></div>
            <div>{{ t('rental.paidLabel') }}: {{ money(rental.total_paid) }}</div>
            <div style="font-weight:700">{{ t('rental.remainingDue') }}: {{ money(rental.remaining_due) }}</div>
        </div>

        <div class="sechead" style="margin-top:16px"><h2>{{ t('rental.itemsHeading') }}</h2></div>
        <button v-if="rental.status !== 'returned'" class="btn ghost" style="width:100%;margin-bottom:10px" @click="openScanner">{{ t('rental.scanToReturn') }}</button>
        <div v-for="item in rawItems" :key="item.id" class="card" style="margin-bottom:10px">
            <div class="mid">
                <b>{{ item.product_name }}</b><span v-if="item.variant_label">{{ item.variant_label }}</span>
                <span>{{ money(item.rent_price) }} × {{ qty(item.qty) }} • {{ t('rental.outstandingReturn') }}: {{ qty(item.outstanding_qty) }}</span>
            </div>
            <div v-if="item.outstanding_qty > 0 && lineState[item.id]" style="margin-top:10px">
                <label style="display:flex;gap:8px;align-items:center;font-weight:700">
                    <input type="checkbox" v-model="lineState[item.id].checked">
                    {{ t('rental.returningThisItem') }}
                </label>
                <template v-if="lineState[item.id].checked">
                    <div class="f2" style="margin-top:10px">
                        <div class="field" style="margin:0">
                            <label>{{ t('rental.qtyMax', { n: qty(item.outstanding_qty) }) }}</label>
                            <input type="number" v-model="lineState[item.id].qty" :max="item.outstanding_qty" min="0" step="any">
                        </div>
                        <div class="field" style="margin:0">
                            <label>{{ t('rental.condition') }}</label>
                            <select v-model="lineState[item.id].condition">
                                <option value="resalable">{{ t('rental.conditionGood') }}</option>
                                <option value="damaged">{{ t('rental.conditionDamaged') }}</option>
                                <option value="lost">{{ t('rental.conditionLost') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="f2" style="margin-top:10px">
                        <div class="field" style="margin:0">
                            <label>{{ t('rental.lateFee') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                            <input type="number" v-model="lineState[item.id].late_fee" min="0" step="any">
                        </div>
                        <div class="field" style="margin:0">
                            <label>{{ t('rental.damageCharge') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                            <input type="number" v-model="lineState[item.id].damage_charge" min="0" step="any">
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div v-if="rental.status !== 'returned'" class="card" style="margin-top:14px;background:var(--surface2)">
            <div v-if="extraCharge > 0">{{ t('rental.extraCharge') }}: {{ money(extraCharge) }}</div>
            <div style="font-weight:700">{{ t('rental.remainingAfterReturn') }}: {{ money(Math.max(0, Number(rental.remaining_due) + extraCharge - Number(paymentAmount || 0))) }}</div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>{{ t('rental.paymentNowAmount') }}</label><input type="number" v-model="paymentAmount" min="0" step="any"></div>
                <div class="field">
                    <label>{{ t('rental.method') }}</label>
                    <select v-model="paymentMethod"><option value="cash">{{ t('pay.cash') }}</option><option value="bkash">{{ t('pay.bkash') }}</option><option value="nagad">{{ t('pay.nagad') }}</option></select>
                </div>
            </div>

            <div v-if="errorMsg" style="color:var(--rose);margin-top:10px">{{ errorMsg }}</div>

            <button class="btn" style="margin-top:12px" :disabled="submitting" @click="submitReturn">
                {{ submitting ? '...' : t('rental.processReturnButton') }}
            </button>
        </div>

        <div class="sechead" style="margin-top:16px"><h2>{{ t('rental.paymentHistory') }}</h2></div>
        <div v-for="p in payments" :key="p.id" class="row">
            <div class="mid"><b>{{ money(p.amount) }}</b><span>{{ p.date }} • {{ p.method }}</span></div>
        </div>
        <div v-if="!payments.length" class="empty">{{ t('rental.noPayments') }}</div>

        <Sheet v-model="scannerOpen" :title="t('rental.scanTitle')">
            <div id="rental-return-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
            <div style="text-align:center;margin-top:10px;font-size:13px;color:var(--mut)">{{ scanHint }}</div>
            <div style="text-align:center;margin-top:4px;font-size:12px;color:var(--dim)">{{ t('rental.scanStaysOpen') }}</div>
            <div v-if="Object.values(lineState).some((l) => l.checked)" class="card" style="margin-top:10px;background:var(--surface2)">
                <div v-for="item in rawItems.filter((i) => lineState[i.id]?.checked)" :key="item.id" style="font-size:13px">
                    ✅ {{ item.product_name }}<span v-if="item.variant_label">({{ item.variant_label }})</span> × {{ qty(lineState[item.id].qty) }}
                </div>
            </div>
            <button class="btn ghost" style="margin-top:10px" @click="closeScanner">{{ t('rental.doneScan') }}</button>
        </Sheet>
    </AppLayout>
</template>
