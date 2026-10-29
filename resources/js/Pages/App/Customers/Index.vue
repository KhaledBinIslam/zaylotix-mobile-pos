<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { reportClientError } from '@/support/reportClientError';
import { useWhatsappSendQueue } from '@/composables/useWhatsappSendQueue';

const props = defineProps({ customers: Array });
const { toast } = useToast();
const { t } = useI18n();
const { hasPerm } = usePermissions();
// this single page/route only requires the `customers` permission to open
// (see routes/web.php), but actually collecting a due or reading payment
// history is a separate `due` grant (PaymentController routes) — a staff
// with `customers` but not `due` must not see those actions at all, not
// just have them fail server-side when tapped
const canDue = computed(() => hasPerm('due'));
const page = usePage();
const hasLoyaltyPoints = computed(() => (page.props.features || []).includes('loyalty_points'));
const hasReferrals = computed(() => (page.props.features || []).includes('customer_referrals'));
const hasCreditLimit = computed(() => (page.props.features || []).includes('credit_limit'));
const hasWholesalePricing = computed(() => (page.props.features || []).includes('wholesale_pricing'));

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const withDue = computed(() => props.customers.filter((c) => c.due > 0).sort((a, b) => b.due - a.due));
const cleared = computed(() => props.customers.filter((c) => c.due <= 0));
const totalDue = computed(() => props.customers.reduce((s, c) => s + Number(c.due), 0));

// --- add customer sheet ---
const addSheet = ref(false);
const addForm = useForm({ name: '', phone: '', due: 0, referred_by_customer_id: null, credit_limit: '', is_wholesale: false });

// "referred by" name-search-as-you-type — same pattern as Pos/Index.vue's
// customer-name search (see docs/FIX_PLAN.md's "রেফারেল" design note):
// find an existing customer by typing their name, pick them from the
// dropdown. referredByName is only ever a display string; the id that
// actually gets submitted lives on addForm.referred_by_customer_id.
const referredByName = ref('');
const referredSearchResults = ref([]);
let referredSearchTimer = null;
watch(referredByName, (val) => {
    clearTimeout(referredSearchTimer);
    addForm.referred_by_customer_id = null;
    const q = (val || '').trim();
    if (q.length < 2) { referredSearchResults.value = []; return; }
    referredSearchTimer = setTimeout(async () => {
        try {
            const res = await fetch(route('app.customers.search') + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
            const data = await res.json();
            referredSearchResults.value = data.results || [];
        } catch (e) { reportClientError(e, 'referredByName search'); }
    }, 400);
});
function pickReferrer(c) {
    referredSearchResults.value = [];
    referredByName.value = c.name;
    addForm.referred_by_customer_id = c.id;
}
function resetAddSheet() {
    addSheet.value = false;
    addForm.reset();
    referredByName.value = '';
    referredSearchResults.value = [];
}
function saveCustomer() {
    addForm.transform((data) => ({ ...data, credit_limit: data.credit_limit === '' ? null : data.credit_limit }))
        .post(route('app.customers.store'), { onSuccess: resetAddSheet });
}

// --- wholesale toggle (see docs/FIX_PLAN.md's Phase 30 design note, Part B) ---
function toggleWholesale(c) {
    router.patch(route('app.customers.wholesale', c.id), { is_wholesale: !c.is_wholesale }, { preserveScroll: true });
}

// --- collect payment sheet ---
const collectSheet = ref(false);
const collecting = ref(null);
const collectForm = useForm({ amount: '', sale_id: '' });
const markingPaid = ref(false);

// Per-invoice reference (Khaled's explicit ask — see docs/FIX_PLAN.md Phase
// 3.2): due_at_checkout is exact; tagged_paid only ever reflects a payment
// that explicitly picked this invoice via the dropdown below, never a
// historical/untagged lump payment — customer.due above remains the one
// real total regardless of what's picked here.
const invoiceList = ref([]);
const invoicesLoading = ref(false);
function loadInvoices(c) {
    invoiceList.value = [];
    invoicesLoading.value = true;
    fetch(route('app.payments.invoices', c.id), { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data) => { invoiceList.value = data.invoices || []; })
        .catch((e) => reportClientError(e, 'loadInvoices'))
        .finally(() => (invoicesLoading.value = false));
}
function invoiceRemaining(inv) {
    return Math.max(0, inv.due_at_checkout - inv.tagged_paid);
}

function openCollect(c) {
    collecting.value = c;
    collectForm.reset();
    collectForm.clearErrors();
    collectSheet.value = true;
    loadInvoices(c);
}
function collect() {
    collectForm.post(route('app.payments.store', collecting.value.id), {
        onSuccess: () => (collectSheet.value = false),
    });
}
// --- credit limit quick-edit (see docs/FIX_PLAN.md's "ক্রেডিট লিমিট"
// design note) — a per-customer override; leaving it blank again falls
// back to the shop-wide default set on the Accounts page. ---
const creditLimitSheet = ref(false);
const editingCreditLimit = ref(null);
const creditLimitForm = useForm({ credit_limit: '' });
function openCreditLimit(c) {
    editingCreditLimit.value = c;
    creditLimitForm.reset();
    creditLimitForm.clearErrors();
    creditLimitForm.credit_limit = c.credit_limit ?? '';
    creditLimitSheet.value = true;
}
function saveCreditLimit() {
    creditLimitForm.transform((data) => ({ ...data, credit_limit: data.credit_limit === '' ? null : data.credit_limit }))
        .patch(route('app.customers.creditLimit', editingCreditLimit.value.id), { onSuccess: () => (creditLimitSheet.value = false) });
}

function markPaid(c) {
    if (!confirm(`${c.name}${t('due.markPaidConfirm', { amount: money(c.due) })}`)) return;
    markingPaid.value = true;
    router.post(route('app.payments.full', c.id), {}, {
        onSuccess: () => (collectSheet.value = false),
        onFinish: () => (markingPaid.value = false),
    });
}

// Phase 39 — spend a void-created advance (store credit) against the
// running due; server computes min(advance, due) itself, this just asks
const applyingAdvance = ref(false);
function applyAdvance(c) {
    applyingAdvance.value = true;
    router.post(route('app.payments.applyAdvance', c.id), {}, {
        onSuccess: () => (collectSheet.value = false),
        onFinish: () => (applyingAdvance.value = false),
    });
}

// --- payment history ledger — date, amount, and the due snapshot right
// before/after each collection (Khaled's explicit request: no way to look
// back and see "who paid what, when, leaving how much still owed" before
// this — customers.due was only ever a single running number). Lazy-
// loaded per customer, same pattern More.vue's settings sheets use. ---
const historySheet = ref(false);
const historyLoading = ref(false);
const historyPayments = ref([]);
function openHistory(c) {
    collecting.value = c;
    historySheet.value = true;
    historyPayments.value = [];
    historyLoading.value = true;
    fetch(route('app.payments.history', c.id), { headers: { Accept: 'application/json' } })
        .then((r) => {
            if (!r.ok) throw new Error(`payments.history responded ${r.status}`);
            return r.json();
        })
        .then((data) => { historyPayments.value = data.payments; })
        .catch((e) => {
            reportClientError(e, 'openHistory');
            toast('⚠️ ' + t('due.historyLoadFailed'));
        })
        .finally(() => (historyLoading.value = false));
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
// Real bug, same root cause as CustomerList/Index.vue's bulk send (see
// docs/WHATSAPP_BULK_PLAN.md): a window.open() fired from a setTimeout
// callback, even staggered, loses the "user gesture" that popup blockers
// require — only the first (sometimes none) of these ever actually
// opened. Fixed the same way via the shared useWhatsappSendQueue.js.
const wa = useWhatsappSendQueue('due');

function sendOffer() {
    if (!offerText.value.trim()) {
        toast('❌ ' + t('due.writeMessageError'));
        return;
    }
    const chosen = props.customers.filter((c) => selected.value[c.id]);
    if (!wa.start(chosen, offerText.value)) {
        toast('❌ ' + t('due.noPhoneSelected'));
        return;
    }
    offerSheet.value = false;
}

function closeQueue() {
    wa.close();
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

        <div class="card" style="background:var(--roseSoft);border-color:var(--line2);margin-bottom:16px">
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
            <button class="btn sm" style="width:100%" :disabled="!selectedCount" @click="offerText = offerText.trim() ? offerText : `প্রিয় {name},\n\n`; offerSheet = true">
                {{ selectedCount }} {{ t('due.selectedWriteOffer') }}
            </button>
        </div>

        <template v-if="withDue.length">
            <div class="sechead"><h2>{{ t('due.collectToday') }}</h2></div>
            <div v-for="c in withDue" :key="c.id">
                <div class="row" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''" @click="selectMode ? toggleSelect(c.id) : (canDue && openHistory(c))">
                    <div class="ava" :style="selected[c.id] ? 'background:var(--gold);color:#fff;border-color:var(--gold)' : ''">{{ selectMode && selected[c.id] ? '✓' : c.name[0] }}</div>
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }}<template v-if="hasLoyaltyPoints"> • ⭐ {{ c.loyalty_points }}</template></span></div>
                    <div class="end"><b class="pill rose">{{ money(c.due) }}</b></div>
                </div>
                <div v-if="!selectMode && canDue" class="btnrow" style="margin:-4px 0 12px 0;padding-left:54px">
                    <button class="btn sm" style="flex:1" @click="openCollect(c)">{{ t('due.collectMoney') }}</button>
                    <button class="btn wa sm" style="flex:1" @click="remindWA(c)">{{ t('due.waReminder') }}</button>
                    <button v-if="hasCreditLimit" class="btn ghost sm" @click="openCreditLimit(c)">💳</button>
                    <button v-if="hasWholesalePricing" class="btn ghost sm" @click="toggleWholesale(c)">{{ c.is_wholesale ? '🏷️✅' : '🏷️' }}</button>
                </div>
            </div>
        </template>

        <template v-if="cleared.length">
            <div class="sechead"><h2>{{ t('due.allCustomers') }}</h2></div>
            <div v-for="c in cleared" :key="c.id">
                <div class="row" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''" @click="selectMode ? toggleSelect(c.id) : (canDue && openHistory(c))">
                    <div class="ava" :style="selected[c.id] ? 'background:var(--gold);color:#fff;border-color:var(--gold)' : ''">{{ selectMode && selected[c.id] ? '✓' : c.name[0] }}</div>
                    <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone || t('due.noNumber') }} • {{ t('due.totalBought') }} {{ money(c.total_spent) }}<template v-if="hasLoyaltyPoints"> • ⭐ {{ c.loyalty_points }}</template></span></div>
                    <div class="end"><span class="pill mint">{{ t('due.paid') }}</span></div>
                </div>
                <div v-if="!selectMode && hasWholesalePricing" class="btnrow" style="margin:-4px 0 12px 0;padding-left:54px">
                    <button class="btn ghost sm" @click="toggleWholesale(c)">{{ c.is_wholesale ? '🏷️✅ ' + t('due.isWholesale') : '🏷️ ' + t('due.isWholesale') }}</button>
                </div>
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
            <div v-if="hasWholesalePricing" class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="addForm.is_wholesale" type="checkbox">
                    {{ t('due.isWholesale') }}
                </label>
            </div>
            <div v-if="hasCreditLimit" class="field">
                <label>{{ t('due.creditLimit') }} <span style="color:var(--dim);font-weight:400">({{ t('stock.optional') }})</span></label>
                <input v-model="addForm.credit_limit" type="number" min="0" :placeholder="t('due.creditLimitPlaceholder')">
                <div v-if="addForm.errors.credit_limit" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.credit_limit }}</div>
            </div>
            <div v-if="hasReferrals" class="field" style="position:relative">
                <label>{{ t('due.referredBy') }} <span style="color:var(--dim);font-weight:400">({{ t('stock.optional') }})</span></label>
                <input v-model="referredByName" :placeholder="t('due.referredByPlaceholder')" autocomplete="off">
                <div v-if="referredSearchResults.length" style="position:absolute;z-index:5;left:0;right:0;top:100%;margin-top:4px;background:var(--card);border:1px solid var(--line);border-radius:var(--radius-sm);overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.12)">
                    <button
                        v-for="c in referredSearchResults" :key="c.id" type="button"
                        style="display:block;width:100%;text-align:left;padding:9px 12px;font-size:13px;border-bottom:1px solid var(--line)"
                        @click="pickReferrer(c)"
                    >
                        <b>{{ c.name }}</b>
                        <span style="color:var(--mut)"> {{ c.phone }}</span>
                    </button>
                </div>
                <div v-if="addForm.referred_by_customer_id" style="font-size:12px;color:var(--green);margin-top:5px;font-weight:600">✅ {{ referredByName }}</div>
                <div v-if="addForm.errors.referred_by_customer_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ addForm.errors.referred_by_customer_id }}</div>
            </div>
            <button class="btn" :disabled="addForm.processing" @click="saveCustomer">
                {{ addForm.processing ? '...' : t('stock.save') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="resetAddSheet">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="creditLimitSheet" :title="editingCreditLimit?.name" :subtitle="t('due.creditLimitSheetSub')">
            <div class="field">
                <label>{{ t('due.creditLimit') }}</label>
                <input v-model="creditLimitForm.credit_limit" type="number" min="0" :placeholder="t('due.creditLimitPlaceholder')">
                <div v-if="creditLimitForm.errors.credit_limit" style="color:var(--rose);font-size:12px;margin-top:6px">{{ creditLimitForm.errors.credit_limit }}</div>
            </div>
            <button class="btn" :disabled="creditLimitForm.processing" @click="saveCreditLimit">{{ creditLimitForm.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="creditLimitSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="collectSheet" :title="collecting?.name">
            <div v-if="collecting" class="card" style="margin-bottom:16px;text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('due.currentDue') }}</div>
                <div style="font-size:28px;font-weight:850;color:var(--rose)">{{ money(collecting.due) }}</div>
                <div v-if="Number(collecting.advance_balance) > 0" style="font-size:13px;font-weight:700;color:var(--green);margin-top:4px">💳 {{ t('due.advanceBalance') }}: {{ money(collecting.advance_balance) }}</div>
            </div>
            <!-- Phase 39 — a voided bill's already-collected due sits as the
                 customer's advance (store credit); one tap applies it against
                 their running due. Pure internal transfer, no cash moves. -->
            <button v-if="collecting && Number(collecting.advance_balance) > 0 && Number(collecting.due) > 0" class="btn ghost" style="margin-bottom:14px;color:var(--green);border-color:var(--green)" :disabled="applyingAdvance" @click="applyAdvance(collecting)">
                {{ applyingAdvance ? '...' : t('due.applyAdvance', { amount: money(Math.min(Number(collecting.advance_balance), Number(collecting.due))) }) }}
            </button>
            <div class="field">
                <label>{{ t('due.collectAmount') }}</label>
                <input v-model="collectForm.amount" type="number" :placeholder="String(collecting?.due || 0)">
                <div v-if="collectForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ collectForm.errors.amount }}</div>
            </div>

            <div v-if="invoiceList.length" class="field">
                <label>{{ t('due.whichInvoice') }} <span style="color:var(--dim);font-weight:400">({{ t('stock.optional') }})</span></label>
                <select v-model="collectForm.sale_id">
                    <option value="">{{ t('due.generalPayment') }}</option>
                    <option v-for="inv in invoiceList" :key="inv.id" :value="inv.id">
                        {{ inv.invoice_no }} ({{ inv.date.slice(0, 10) }}) — {{ t('due.remaining') }} {{ money(invoiceRemaining(inv)) }}
                    </option>
                </select>
            </div>
            <div v-if="invoiceList.length" class="card" style="margin-bottom:14px;font-size:12px;color:var(--dim)">
                {{ t('due.invoiceBreakdownNote') }}
                <div v-for="inv in invoiceList" :key="inv.id" style="display:flex;justify-content:space-between;margin-top:6px;color:var(--mut)">
                    <span>{{ inv.invoice_no }} ({{ inv.date.slice(0, 10) }})</span>
                    <span>{{ t('due.dueAtCheckout') }} {{ money(inv.due_at_checkout) }}<template v-if="inv.tagged_paid > 0"> • {{ t('due.taggedPaid') }} {{ money(inv.tagged_paid) }}</template></span>
                </div>
            </div>

            <button class="btn" :disabled="collectForm.processing" @click="collect">
                {{ collectForm.processing ? '...' : t('due.collectSubmit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" :disabled="markingPaid" @click="markPaid(collecting)">
                {{ markingPaid ? '...' : `${t('due.markFullPaid')} (${money(collecting?.due || 0)})` }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="collectSheet = false; openHistory(collecting)">
                {{ t('due.paymentHistory') }}
            </button>
        </Sheet>

        <Sheet v-model="historySheet" :title="collecting?.name" :subtitle="t('due.paymentHistorySub')">
            <div v-if="historyLoading" class="empty"><div class="big">⏳</div></div>
            <template v-else-if="historyPayments.length">
                <div v-for="p in historyPayments" :key="p.id" class="card" style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span style="font-size:12px;color:var(--mut)">{{ p.date.slice(0, 10) }}<template v-if="p.user"> • {{ t('due.collectedBy') }} {{ p.user.name }}</template></span>
                        <b style="color:var(--green);font-size:15px">+{{ money(p.amount) }}</b>
                    </div>
                    <div v-if="p.due_before !== null && p.due_after !== null" style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:var(--dim)">
                        <span>{{ t('due.dueBefore') }}: {{ money(p.due_before) }}</span>
                        <span>→</span>
                        <span>{{ t('due.dueAfter') }}: {{ money(p.due_after) }}</span>
                    </div>
                </div>
            </template>
            <div v-else class="empty"><div class="big">📜</div>{{ t('due.noPaymentsYet') }}</div>
            <button class="btn ghost" style="margin-top:10px" @click="historySheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="offerSheet" :title="t('due.offerSheetTitle')" :subtitle="t('due.offerSheetSub')">
            <div class="field">
                <label>{{ t('due.writeMessage') }}</label>
                <textarea v-model="offerText" rows="4" :placeholder="t('due.messagePlaceholder')"></textarea>
                <div style="font-size:11.5px;color:var(--mut);margin-top:4px">{{ t('customerList.templateVarsHint') }}</div>
            </div>
            <button class="btn wa" @click="sendOffer">{{ t('due.sendToWA') }} ({{ selectedCount }})</button>
            <button class="btn ghost" style="margin-top:10px" @click="offerSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="wa.sheet.value" :title="t('customerList.queueSheetTitle')">
            <div v-if="wa.skippedInvalid.value.length" class="card" style="margin-bottom:12px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                {{ t('customerList.queueSkippedInvalid', { n: wa.skippedInvalid.value.length }) }}
            </div>

            <div class="card" style="margin-bottom:12px;font-size:12.5px;color:var(--mut)">{{ t('customerList.queueSpamWarning') }}</div>

            <div v-if="wa.showPauseReminder.value" class="card" style="margin-bottom:12px;background:var(--goldSoft);font-size:12.5px">
                ⏸️ {{ t('customerList.queuePauseReminder') }}
            </div>

            <div style="font-size:13px;color:var(--mut);margin-bottom:10px">{{ t('customerList.queueProgress', { sent: wa.sent.value, total: wa.total.value }) }}</div>

            <template v-if="!wa.done.value">
                <div class="card" style="margin-bottom:14px">
                    <b>{{ wa.current.value?.name }}</b>
                    <div style="font-size:13px;color:var(--mut)">📞 {{ wa.current.value?.phone }}</div>
                </div>
                <template v-if="wa.awaitingConfirmId.value">
                    <div class="card" style="margin-bottom:8px;font-size:12.5px;color:var(--mut)">{{ t('customerList.queueAwaitingConfirm') }}</div>
                    <button class="btn" style="margin-bottom:8px" @click="wa.confirmSent()">✓ {{ t('customerList.queueConfirmSent') }}</button>
                </template>
                <button v-else class="btn wa" style="margin-bottom:8px" @click="wa.sendToCurrent()">
                    💬 {{ t('customerList.queueSendNext', { current: wa.sent.value + wa.skipped.value + 1, total: wa.total.value }) }}
                </button>
                <button class="btn ghost sm" style="margin-bottom:8px" @click="wa.skipCurrent()">{{ t('customerList.queueSkip') }}</button>
            </template>
            <div v-else class="card" style="margin-bottom:14px;background:var(--goldSoft)">
                ✅ {{ t('customerList.queueDoneSummary', { sent: wa.sent.value, skipped: wa.skipped.value }) }}
            </div>

            <button v-if="wa.lastConfirmedId.value" class="btn ghost sm" style="margin-bottom:8px" @click="wa.undoLastConfirm()">↩️ {{ t('customerList.queueUndoLastSent') }}</button>

            <button class="btn ghost" @click="closeQueue">{{ wa.done.value ? t('common.close') : t('customerList.queueStop') }}</button>
        </Sheet>
    </AppLayout>
</template>
