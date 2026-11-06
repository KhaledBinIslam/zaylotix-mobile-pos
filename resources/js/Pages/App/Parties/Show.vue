<script setup>
/**
 * One party's page (feature: parties — SEAMAN Phase 5): current due,
 * bill-wise list, payment history with void, and the monthly statement —
 * previewed on screen from the exact same server builder that renders
 * the A4 PDF, so screen and paper can never disagree.
 *
 * WhatsApp share: wa.me cannot attach a file, so (a) devices whose
 * browser can share files (Android Chrome) get the real PDF via the Web
 * Share API; (b) everyone else falls back to a WhatsApp text with a
 * signed, expiring, read-only link to the same PDF.
 */
import { ref, computed, onMounted } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import Sheet from '@/Components/Sheet.vue';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { reportClientError } from '@/support/reportClientError';

const props = defineProps({ party: Object, bills: Array, payments: Array, totalBilled: Number });
const { toast } = useToast();
const { t } = useI18n();
const { hasPerm } = usePermissions();
const canDue = computed(() => hasPerm('due'));

const money = (n) => '৳' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
const displayName = computed(() => props.party.company_name || props.party.name);
const methodLabel = (m) => ({ cash: t('party.cash'), bkash: 'বিকাশ', nagad: 'নগদ', bank: t('party.bank'), advance: t('party.advance') }[m] || m);

// --- collect a payment (reuses the existing due endpoints untouched) ---
const collectSheet = ref(false);
const collectForm = useForm({ amount: '', method: 'cash', sale_id: null });
function openCollect() {
    collectForm.reset();
    collectForm.clearErrors();
    collectSheet.value = true;
}
function collect() {
    collectForm.post(route('app.payments.store', props.party.id), { onSuccess: () => (collectSheet.value = false) });
}

// --- void a payment (audit-trailed server-side) ---
const voiding = ref(false);
function voidPayment(p) {
    if (!confirm(t('party.voidConfirm', { amount: money(p.amount) }))) return;
    voiding.value = true;
    router.post(route('app.payments.void', p.id), {}, { onFinish: () => (voiding.value = false) });
}

// --- statement ---
const today = new Date().toISOString().slice(0, 10);
const monthStart = today.slice(0, 8) + '01';
const from = ref(monthStart);
const to = ref(today);
const stmt = ref(null);
const stmtLoading = ref(false);
function loadStatement() {
    stmtLoading.value = true;
    fetch(route('app.parties.statement', props.party.id) + `?from=${from.value}&to=${to.value}`, { headers: { Accept: 'application/json' } })
        .then((r) => { if (!r.ok) throw new Error(`statement responded ${r.status}`); return r.json(); })
        .then((data) => { stmt.value = data; })
        .catch((e) => { reportClientError(e, 'party statement'); toast('⚠️ ' + t('party.stmtFailed')); })
        .finally(() => (stmtLoading.value = false));
}
onMounted(loadStatement);

const pdfUrl = computed(() => route('app.parties.statementPdf', props.party.id) + `?from=${from.value}&to=${to.value}`);

// --- WhatsApp share ---
const sharing = ref(false);
async function shareWhatsApp() {
    sharing.value = true;
    try {
        // (a) real file share where the browser supports it
        if (navigator.canShare) {
            try {
                const resp = await fetch(pdfUrl.value);
                if (resp.ok) {
                    const blob = await resp.blob();
                    const file = new File([blob], `statement-${from.value}-${to.value}.pdf`, { type: 'application/pdf' });
                    if (navigator.canShare({ files: [file] })) {
                        await navigator.share({ files: [file], title: displayName.value });
                        return;
                    }
                }
            } catch (e) {
                if (e?.name === 'AbortError') return; // user closed the share sheet — not an error
                // fall through to the link fallback
            }
        }

        // (b) fallback: WhatsApp text + signed expiring link
        const resp = await fetch(route('app.parties.shareLink', props.party.id) + `?from=${from.value}&to=${to.value}`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
        });
        if (!resp.ok) throw new Error(`shareLink responded ${resp.status}`);
        const data = await resp.json();
        const num = '88' + (props.party.phone || '').replace(/\D/g, '').replace(/^88/, '');
        const closing = stmt.value ? money(stmt.value.closing) : money(props.party.due);
        const msg = t('party.waMessage', { name: displayName.value, due: closing, from: from.value, to: to.value }) + '\n' + data.url;
        window.open('https://wa.me/' + (props.party.phone ? num : '') + '?text=' + encodeURIComponent(msg), '_blank');
    } catch (e) {
        reportClientError(e, 'party WhatsApp share');
        toast('⚠️ ' + t('party.shareFailed'));
    } finally {
        sharing.value = false;
    }
}
</script>

<template>
    <Head :title="displayName" />
    <AppLayout active="more">
        <Link :href="route('app.parties.index')" class="btn ghost sm" style="margin-bottom:12px;display:inline-block;width:auto;text-decoration:none">← {{ t('party.title') }}</Link>

        <div class="card" style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:12px">
                <div class="ava" style="font-size:22px">{{ party.party_type === 'company' ? '🏢' : '👤' }}</div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:17px;font-weight:800">{{ displayName }}</div>
                    <div style="font-size:12.5px;color:var(--mut)">
                        <span v-if="party.contact_person">{{ t('party.contactPerson') }}: {{ party.contact_person }} · </span>
                        {{ party.phone || 'Nil' }}
                    </div>
                    <div v-if="party.address" style="font-size:12px;color:var(--mut)">{{ party.address }}</div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:11px;color:var(--mut)">{{ t('party.currentDue') }}</div>
                    <div :style="{ fontSize: '21px', fontWeight: 800, color: party.due > 0 ? 'var(--rose)' : 'var(--grn)' }">{{ money(party.due) }}</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px">
                <button v-if="canDue" class="btn sm" style="flex:1" @click="openCollect">💰 {{ t('party.collect') }}</button>
                <a :href="pdfUrl" class="btn sm ghost" style="flex:1;text-align:center;text-decoration:none">📄 {{ t('party.pdf') }}</a>
                <button class="btn sm ghost" style="flex:1" :disabled="sharing" @click="shareWhatsApp">{{ sharing ? '…' : '🟢 WhatsApp' }}</button>
            </div>
            <div v-if="party.notes" style="margin-top:10px;font-size:12.5px;color:var(--mut);border-top:1px solid var(--bd);padding-top:8px">📝 {{ party.notes }}</div>
        </div>

        <!-- statement -->
        <div class="card" style="margin-bottom:12px">
            <div style="font-weight:800;margin-bottom:8px">📑 {{ t('party.statement') }}</div>
            <HowToHint :text="t('party.stmtHowTo')" />
            <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px">
                <div class="field" style="flex:1;margin-bottom:0"><label>{{ t('rep.from') }}</label><input v-model="from" type="date"></div>
                <div class="field" style="flex:1;margin-bottom:0"><label>{{ t('rep.to') }}</label><input v-model="to" type="date"></div>
                <button class="btn sm" style="width:auto;padding:10px 14px" :disabled="stmtLoading" @click="loadStatement">{{ stmtLoading ? '…' : t('party.stmtLoad') }}</button>
            </div>
            <template v-if="stmt">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
                    <span>{{ t('party.opening') }}: <b>{{ money(stmt.opening) }}</b></span>
                    <span>{{ t('party.closing') }}: <b :style="{ color: stmt.closing > 0 ? 'var(--rose)' : 'var(--grn)' }">{{ money(stmt.closing) }}</b></span>
                </div>
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:12px;border-collapse:collapse">
                        <thead><tr style="color:var(--mut);text-align:left"><th style="padding:4px">{{ t('party.date') }}</th><th style="padding:4px">{{ t('party.desc') }}</th><th style="padding:4px;text-align:right">{{ t('party.bill') }}</th><th style="padding:4px;text-align:right">{{ t('party.paid') }}</th><th style="padding:4px;text-align:right">{{ t('party.balance') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in stmt.rows" :key="i" style="border-top:1px solid var(--bd)">
                                <td style="padding:5px 4px;white-space:nowrap">{{ row.date }}</td>
                                <td style="padding:5px 4px">{{ row.type === 'bill' ? 'বিল ' + row.label : 'জমা (' + methodLabel(row.label) + ')' }}</td>
                                <td style="padding:5px 4px;text-align:right">{{ row.bill_total != null ? money(row.bill_total) : '' }}</td>
                                <td style="padding:5px 4px;text-align:right">{{ row.paid > 0 ? money(row.paid) : '' }}</td>
                                <td style="padding:5px 4px;text-align:right;font-weight:600">{{ money(row.balance) }}</td>
                            </tr>
                            <tr v-if="!stmt.rows.length"><td colspan="5" style="padding:10px;text-align:center;color:var(--mut)">{{ t('party.stmtEmpty') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- bills -->
        <div class="card" style="margin-bottom:12px">
            <div style="font-weight:800;margin-bottom:8px">🧾 {{ t('party.bills') }} <span style="font-weight:400;font-size:12px;color:var(--mut)">· {{ t('party.totalBilled') }}: {{ money(totalBilled) }}</span></div>
            <div v-for="b in bills" :key="b.id" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-top:1px solid var(--bd);font-size:13px">
                <div style="flex:1;min-width:0">
                    <b>{{ b.invoice_no }}</b> <span style="color:var(--mut);font-size:12px">· {{ b.date }}</span>
                    <div style="font-size:12px;color:var(--mut)">
                        {{ t('party.bill') }}: {{ money(b.total) }} · {{ t('party.paid') }}: {{ money(b.paid_at_checkout + b.tagged_paid) }}
                        <span v-if="Math.max(0, b.due_at_checkout - b.tagged_paid) > 0" style="color:var(--rose)"> · {{ t('party.due') }}: {{ money(Math.max(0, b.due_at_checkout - b.tagged_paid)) }}</span>
                    </div>
                </div>
                <a :href="route('app.parties.billPdf', [party.id, b.id])" class="btn sm ghost" style="width:auto;padding:6px 10px;font-size:12px;text-decoration:none">📄 PDF</a>
            </div>
            <div v-if="!bills.length" style="padding:8px;text-align:center;color:var(--mut);font-size:13px">{{ t('party.noBills') }}</div>
        </div>

        <!-- payment history -->
        <div class="card">
            <div style="font-weight:800;margin-bottom:8px">💰 {{ t('party.payments') }}</div>
            <div v-for="p in payments" :key="p.id" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-top:1px solid var(--bd);font-size:13px" :style="p.voided_at ? 'opacity:.55' : ''">
                <div style="flex:1;min-width:0">
                    <b :style="p.voided_at ? 'text-decoration:line-through' : (p.amount < 0 ? 'color:var(--rose)' : '')">{{ money(p.amount) }}</b>
                    <span style="color:var(--mut);font-size:12px"> · {{ methodLabel(p.method) }} · {{ p.date }}</span>
                    <span v-if="p.voided_at" style="color:var(--rose);font-size:11.5px"> · {{ t('party.voided') }}</span>
                    <span v-else-if="p.void_of_payment_id" style="color:var(--mut);font-size:11.5px"> · {{ t('party.voidEntry') }}</span>
                    <div style="font-size:11.5px;color:var(--mut)">{{ t('party.dueSnap', { b: money(p.due_before), a: money(p.due_after) }) }}<span v-if="p.user"> · {{ p.user.name }}</span></div>
                </div>
                <button v-if="canDue && !p.voided_at && !p.void_of_payment_id && p.amount > 0" class="btn sm ghost rose" style="width:auto;padding:6px 10px;font-size:12px" :disabled="voiding" @click="voidPayment(p)">{{ t('party.void') }}</button>
            </div>
            <div v-if="!payments.length" style="padding:8px;text-align:center;color:var(--mut);font-size:13px">{{ t('party.noPayments') }}</div>
        </div>

        <Sheet v-model="collectSheet" :title="t('party.collectTitle', { name: displayName })">
            <div class="field"><label>{{ t('party.amount') }} ({{ t('party.currentDue') }}: {{ money(party.due) }})</label><input v-model="collectForm.amount" type="number" inputmode="decimal" min="0"></div>
            <div v-if="collectForm.errors.amount" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ collectForm.errors.amount }}</div>
            <div class="field">
                <label>{{ t('party.method') }}</label>
                <select v-model="collectForm.method">
                    <option value="cash">{{ t('party.cash') }}</option>
                    <option value="bank">{{ t('party.bank') }}</option>
                    <option value="bkash">বিকাশ</option>
                    <option value="nagad">নগদ</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('party.againstBill') }}</label>
                <select v-model="collectForm.sale_id">
                    <option :value="null">{{ t('party.noSpecificBill') }}</option>
                    <option v-for="b in bills.filter((x) => Math.max(0, x.due_at_checkout - x.tagged_paid) > 0)" :key="b.id" :value="b.id">{{ b.invoice_no }} — {{ money(Math.max(0, b.due_at_checkout - b.tagged_paid)) }}</option>
                </select>
            </div>
            <button class="btn" :disabled="collectForm.processing" @click="collect">{{ t('party.collect') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="collectSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
