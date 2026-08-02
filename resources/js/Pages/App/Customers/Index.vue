<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ customers: Array });
const { toast } = useToast();
const { t } = useI18n();
const hasLoyaltyPoints = computed(() => (usePage().props.features || []).includes('loyalty_points'));

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const withDue = computed(() => props.customers.filter((c) => c.due > 0).sort((a, b) => b.due - a.due));
const cleared = computed(() => props.customers.filter((c) => c.due <= 0));
const totalDue = computed(() => props.customers.reduce((s, c) => s + Number(c.due), 0));

// --- add customer sheet ---
const addSheet = ref(false);
const addForm = useForm({ name: '', phone: '', due: 0 });
function saveCustomer() {
    addForm.post(route('app.customers.store'), { onSuccess: () => { addSheet.value = false; addForm.reset(); } });
}

// --- collect payment sheet ---
const collectSheet = ref(false);
const collecting = ref(null);
const collectForm = useForm({ amount: '' });
const markingPaid = ref(false);

function openCollect(c) {
    collecting.value = c;
    collectForm.reset();
    collectForm.clearErrors();
    collectSheet.value = true;
}
function collect() {
    collectForm.post(route('app.payments.store', collecting.value.id), {
        onSuccess: () => (collectSheet.value = false),
    });
}
function markPaid(c) {
    if (!confirm(`${c.name}${t('due.markPaidConfirm', { amount: money(c.due) })}`)) return;
    markingPaid.value = true;
    router.post(route('app.payments.full', c.id), {}, {
        onSuccess: () => (collectSheet.value = false),
        onFinish: () => (markingPaid.value = false),
    });
}

function remindWA(c) {
    const num = '88' + (c.phone || '').replace(/\D/g, '').replace(/^88/, '');
    const msg = `বিসমিল্লাহ। ${c.name},\n\nআপনার বাকি: *${money(c.due)}*\n\nসুবিধামত পরিশোধ করলে খুশি হবো। ধন্যবাদ 🙏`;
    window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(msg), '_blank');
}

// --- offer broadcast ---
const selectMode = ref(false);
const selected = ref({});
const offerSheet = ref(false);
const offerText = ref('');
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length);
function toggleSelect(id) {
    selected.value[id] = !selected.value[id];
}
function selectAll() {
    props.customers.forEach((c) => (selected.value[c.id] = true));
}
function clearSelect() {
    selected.value = {};
}
function sendOffer() {
    if (!offerText.value.trim()) {
        toast('❌ ' + t('due.writeMessageError'));
        return;
    }
    const targets = props.customers.filter((c) => selected.value[c.id] && c.phone);
    if (!targets.length) {
        toast('❌ ' + t('due.noPhoneSelected'));
        return;
    }
    targets.forEach((c, i) => {
        setTimeout(() => {
            const num = '88' + c.phone.replace(/\D/g, '').replace(/^88/, '');
            const text = `প্রিয় ${c.name},\n\n${offerText.value}`;
            window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
        }, i * 600);
    });
    toast(`📤 ${targets.length} ${t('due.sendingOffer')}`);
    offerSheet.value = false;
    offerText.value = '';
    selectMode.value = false;
    selected.value = {};
}
</script>

<template>
    <Head :title="t('nav.dueFull')" />
    <AppLayout active="due">
        <div class="pgttl">{{ t('nav.dueFull') }}</div>
        <div class="pgsub">{{ t('due.subtitle') }}</div>
        <HowToHint screen-key="due" />

        <div class="card" style="background:linear-gradient(135deg,var(--roseSoft),#fff);border-color:var(--line2);margin-bottom:16px">
            <div class="k" style="color:var(--mut);font-size:12px">{{ t('due.totalReceivable') }}</div>
            <div style="font-size:30px;font-weight:850;color:var(--rose);letter-spacing:-.5px">{{ money(totalDue) }}</div>
            <div style="font-size:12px;color:var(--dim);margin-top:2px">{{ withDue.length }} {{ t('home.customers') }}</div>
        </div>

        <div class="btnrow" style="margin-bottom:16px">
            <button class="btn ghost sm" style="flex:1" @click="addSheet = true">{{ t('due.addCustomer') }}</button>
            <button class="btn ghost sm" style="flex:1" @click="selectMode = !selectMode; clearSelect()">
                {{ selectMode ? t('due.cancelSelect') : t('due.sendOffer') }}
            </button>
        </div>

        <div v-if="selectMode" class="card" style="margin-bottom:14px">
            <div class="btnrow" style="margin-bottom:10px">
                <button class="btn ghost sm" style="flex:1" @click="selectAll">{{ t('due.selectAll') }}</button>
                <button class="btn ghost sm" style="flex:1" @click="clearSelect">{{ t('due.cancelSelect') }}</button>
            </div>
            <button class="btn sm" style="width:100%" :disabled="!selectedCount" @click="offerSheet = true">
                {{ selectedCount }} {{ t('due.selectedWriteOffer') }}
            </button>
        </div>

        <template v-if="withDue.length">
            <div class="sechead"><h2>{{ t('due.collectToday') }}</h2></div>
            <div v-for="c in withDue" :key="c.id">
                <div class="row" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''" @click="selectMode ? toggleSelect(c.id) : null">
                    <div class="ava" :style="selected[c.id] ? 'background:var(--gold);color:#fff;border-color:var(--gold)' : ''">{{ selectMode && selected[c.id] ? '✓' : c.name[0] }}</div>
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }}<template v-if="hasLoyaltyPoints"> • ⭐ {{ c.loyalty_points }}</template></span></div>
                    <div class="end"><b class="pill rose">{{ money(c.due) }}</b></div>
                </div>
                <div v-if="!selectMode" class="btnrow" style="margin:-4px 0 12px 0;padding-left:54px">
                    <button class="btn sm" style="flex:1" @click="openCollect(c)">{{ t('due.collectMoney') }}</button>
                    <button class="btn wa sm" style="flex:1" @click="remindWA(c)">{{ t('due.waReminder') }}</button>
                </div>
            </div>
        </template>

        <template v-if="cleared.length">
            <div class="sechead"><h2>{{ t('due.allCustomers') }}</h2></div>
            <div v-for="c in cleared" :key="c.id" class="row" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''" @click="selectMode ? toggleSelect(c.id) : null">
                <div class="ava" :style="selected[c.id] ? 'background:var(--gold);color:#fff;border-color:var(--gold)' : ''">{{ selectMode && selected[c.id] ? '✓' : c.name[0] }}</div>
                <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone || t('due.noNumber') }} • {{ t('due.totalBought') }} {{ money(c.total_spent) }}<template v-if="hasLoyaltyPoints"> • ⭐ {{ c.loyalty_points }}</template></span></div>
                <div class="end"><span class="pill mint">{{ t('due.paid') }}</span></div>
            </div>
        </template>

        <div v-if="!customers.length" class="empty"><div class="big">👥</div>{{ t('due.noCustomers') }}</div>

        <Sheet v-model="addSheet" :title="t('due.addSheetTitle')">
            <div class="field">
                <label>{{ t('due.name') }}</label>
                <input v-model="addForm.name" :placeholder="t('due.namePlaceholder')">
                <div v-if="addForm.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('due.mobile') }}</label>
                <input v-model="addForm.phone" placeholder="01812-111222">
                <div v-if="addForm.errors.phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.phone }}</div>
            </div>
            <div class="field"><label>{{ t('due.startingDue') }}</label><input v-model="addForm.due" type="number" placeholder="0"></div>
            <button class="btn" :disabled="addForm.processing" @click="saveCustomer">
                {{ addForm.processing ? '...' : t('stock.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="addSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="collectSheet" :title="collecting?.name">
            <div v-if="collecting" class="card" style="margin-bottom:16px;text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('due.currentDue') }}</div>
                <div style="font-size:28px;font-weight:850;color:var(--rose)">{{ money(collecting.due) }}</div>
            </div>
            <div class="field">
                <label>{{ t('due.collectAmount') }}</label>
                <input v-model="collectForm.amount" type="number" :placeholder="String(collecting?.due || 0)">
                <div v-if="collectForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ collectForm.errors.amount }}</div>
            </div>
            <button class="btn" :disabled="collectForm.processing" @click="collect">
                {{ collectForm.processing ? '...' : t('due.collectSubmit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" :disabled="markingPaid" @click="markPaid(collecting)">
                {{ markingPaid ? '...' : `${t('due.markFullPaid')} (${money(collecting?.due || 0)})` }}
            </button>
        </Sheet>

        <Sheet v-model="offerSheet" :title="t('due.offerSheetTitle')" :subtitle="t('due.offerSheetSub')">
            <div class="field"><label>{{ t('due.writeMessage') }}</label><textarea v-model="offerText" rows="4" :placeholder="t('due.messagePlaceholder')"></textarea></div>
            <button class="btn wa" @click="sendOffer">{{ t('due.sendToWA') }} ({{ selectedCount }})</button>
            <button class="btn ghost" style="margin-top:10px" @click="offerSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
