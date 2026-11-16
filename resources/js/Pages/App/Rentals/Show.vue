<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from '@/composables/useToast';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';

const props = defineProps({ rental: Object, rawItems: Array, returns: Array, payments: Array });
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
    if (!lines.length) { errorMsg.value = 'অন্তত একটা আইটেম বাছাই করুন।'; return; }

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
            errorMsg.value = data.message || Object.values(data.errors || {})[0]?.[0] || 'একটা সমস্যা হয়েছে।';
            return;
        }
        pendingUuid = null;
        toast('✅ ফেরত প্রসেস হয়েছে');
        window.location.reload();
    } catch (e) {
        errorMsg.value = 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Head :title="'রেন্টাল — ' + rental.invoice_no" />
    <AppLayout active="rentals">
        <div class="pgttl">📅 {{ rental.invoice_no }}</div>
        <div class="pgsub">{{ rental.customer?.name }} • {{ rental.rental_date }} → {{ rental.expected_return_date }}</div>

        <div class="card" style="margin-top:10px">
            <div>মোট ভাড়া: <b>{{ money(rental.total_rent_amount) }}</b></div>
            <div>পরিশোধিত: {{ money(rental.total_paid) }}</div>
            <div style="font-weight:700">বাকি আছে: {{ money(rental.remaining_due) }}</div>
        </div>

        <div class="sechead" style="margin-top:16px"><h2>আইটেম</h2></div>
        <div v-for="item in rawItems" :key="item.id" class="row" style="width:100%">
            <div class="mid" style="flex:1">
                <b>{{ item.product_name }}</b><span v-if="item.variant_label">{{ item.variant_label }}</span>
                <span>{{ money(item.rent_price) }} × {{ qty(item.qty) }} • বাকি ফেরত: {{ qty(item.outstanding_qty) }}</span>
            </div>
            <template v-if="item.outstanding_qty > 0 && lineState[item.id]">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="checkbox" v-model="lineState[item.id].checked">
                    <input type="number" v-model="lineState[item.id].qty" :max="item.outstanding_qty" min="0" step="any" style="width:60px" :disabled="!lineState[item.id].checked">
                    <select v-model="lineState[item.id].condition" style="width:120px" :disabled="!lineState[item.id].checked">
                        <option value="resalable">ভালো আছে</option>
                        <option value="damaged">ক্ষতিগ্রস্ত</option>
                        <option value="lost">হারিয়ে গেছে</option>
                    </select>
                    <input type="number" v-model="lineState[item.id].late_fee" placeholder="লেট ফি" min="0" step="any" style="width:80px" :disabled="!lineState[item.id].checked">
                    <input type="number" v-model="lineState[item.id].damage_charge" placeholder="ক্ষতির চার্জ" min="0" step="any" style="width:90px" :disabled="!lineState[item.id].checked">
                </div>
            </template>
        </div>

        <div v-if="rental.status !== 'returned'" class="card" style="margin-top:14px;background:var(--surface2)">
            <div v-if="extraCharge > 0">অতিরিক্ত চার্জ (লেট ফি + ক্ষতি): {{ money(extraCharge) }}</div>
            <div style="font-weight:700">ফেরতের পর বাকি থাকবে: {{ money(Math.max(0, Number(rental.remaining_due) + extraCharge - Number(paymentAmount || 0))) }}</div>

            <div class="f2" style="margin-top:10px">
                <div class="field"><label>এখন কত টাকা দিলো</label><input type="number" v-model="paymentAmount" min="0" step="any"></div>
                <div class="field">
                    <label>পদ্ধতি</label>
                    <select v-model="paymentMethod"><option value="cash">ক্যাশ</option><option value="bkash">বিকাশ</option><option value="nagad">নগদ</option></select>
                </div>
            </div>

            <div v-if="errorMsg" style="color:var(--rose);margin-top:10px">{{ errorMsg }}</div>

            <button class="btn" style="margin-top:12px" :disabled="submitting" @click="submitReturn">
                {{ submitting ? '...' : 'প্রোডাক্ট ফেরত নিন' }}
            </button>
        </div>

        <div class="sechead" style="margin-top:16px"><h2>পেমেন্ট হিস্ট্রি</h2></div>
        <div v-for="p in payments" :key="p.id" class="row">
            <div class="mid"><b>{{ money(p.amount) }}</b><span>{{ p.date }} • {{ p.method }}</span></div>
        </div>
        <div v-if="!payments.length" class="empty">এখনো কোনো পেমেন্ট নেই</div>
    </AppLayout>
</template>
