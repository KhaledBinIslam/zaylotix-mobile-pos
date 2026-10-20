<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useWhatsappSendQueue } from '@/composables/useWhatsappSendQueue';
import { applySegment, daysSince } from '@/support/customerSegments';

const props = defineProps({ customers: Array, whatsappApiReady: Boolean });
const { t } = useI18n();
const { toast } = useToast();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
// Missing feature (see docs/FIX_PLAN.md Phase 7): this was a flat,
// undifferentiated list with search only — the underlying data
// (total_spent, visits, due, updated_at-as-last-activity) already existed
// on Customer, just never exposed as a sort/filter. 'all' keeps the exact
// previous behavior (alphabetical, no filtering) unchanged.
const segment = ref('all');
const filtered = computed(() => {
    const rows = props.customers.filter((c) =>
        !q.value || c.name.toLowerCase().includes(q.value.toLowerCase()) || (c.phone || '').includes(q.value)
    );
    return applySegment(rows, segment.value);
});

const selected = ref({});
const selectedIds = computed(() => Object.keys(selected.value).filter((id) => selected.value[id]).map(Number));
const selectedCount = computed(() => selectedIds.value.length);
function toggle(c) {
    if (selected.value[c.id]) delete selected.value[c.id];
    else selected.value[c.id] = true;
}
function selectAll() {
    filtered.value.forEach((c) => (selected.value[c.id] = true));
}
function clearSelection() {
    selected.value = {};
}

/**
 * "মার্কেটিং মেসেজ চান না" — per-customer opt-out, excluded server-side from
 * both this queue's target-building AND WhatsappBulkController::send()
 * (see CustomerListController::toggleMarketing(), docs/WHATSAPP_BULK_PLAN.md
 * part D). No dedicated customer-edit form exists in this app, so this is a
 * lightweight inline toggle right on the row instead of a new page.
 */
function toggleMarketing(c) {
    router.patch(route('app.customerList.toggleMarketing', c.id), {}, { preserveScroll: true });
}

// ---------------- bulk WhatsApp — two methods on purpose: a free wa.me
// link (works for every shop, no setup, text only) vs the real Meta Cloud
// API (automatic, supports an image, but needs WhatsApp Business connected
// — see WhatsappBulkController::send(), reused as-is here) ----------------
//
// Real bug, reported live: the free method used to fire one window.open()
// per customer from inside a staggered setTimeout loop. A window.open()
// not triggered synchronously within a real user tap loses its "user
// gesture" status — browsers/the Android TWA's popup blocker silently
// swallow every one of those async calls except (sometimes) the very
// first, so only one chat ever actually opened. See
// docs/WHATSAPP_BULK_PLAN.md for the full root-cause writeup. The fix:
// window.open() now only ever happens directly inside sendToCurrent()'s
// own click handler, one customer at a time, by direct owner tap — never
// automated, never deferred.
const sendSheet = ref(false);
const method = ref('free');
const freeText = ref('');

function openSendSheet() {
    if (!selectedCount.value) return;
    method.value = props.whatsappApiReady ? 'api' : 'free';
    if (!freeText.value.trim()) freeText.value = `প্রিয় {name},\n\n`;
    sendSheet.value = true;
}

// ---------------- "পরবর্তী জনকে পাঠান" queue — see useWhatsappSendQueue.js ----------------
const wa = useWhatsappSendQueue('cl');
function startQueue() {
    if (!freeText.value.trim()) {
        toast('❌ ' + t('customerList.writeMessageError'));
        return;
    }
    const chosen = props.customers.filter((c) => selected.value[c.id]);
    if (!wa.start(chosen, freeText.value)) {
        toast('❌ ' + t('customerList.noPhoneSelected'));
        return;
    }
    sendSheet.value = false;
}
function closeQueue() {
    wa.close();
    clearSelection();
    freeText.value = '';
}

const apiForm = useForm({ send_type: 'text', message: '', image: null, customer_ids: [] });
function sendApi() {
    if (!selectedCount.value) return;
    apiForm.customer_ids = selectedIds.value;
    apiForm.post(route('app.whatsappBulk.send'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { sendSheet.value = false; apiForm.reset(); clearSelection(); },
    });
}

// ---------------- export selected rows — plain spreadsheet (Customer/
// Phone/Total Bought/Visits/Due) or a .vcf contact card bundle so they can
// be imported into the phone's Contacts app and messaged via WhatsApp's
// own native "New broadcast" feature, see docs/WHATSAPP_BULK_PLAN.md B ----
function exportSelected(fmt) {
    if (!selectedCount.value) return;
    const qs = selectedIds.value.map((id) => 'customer_ids[]=' + id).join('&');
    window.open(route('app.customerList.exportSelected') + '?' + qs + '&format=' + fmt, '_blank');
}
</script>

<template>
    <Head :title="t('customerList.title')" />
    <AppLayout active="customerList">
        <div class="pgttl">{{ t('customerList.title') }}</div>
        <div class="pgsub">{{ t('customerList.subtitle') }}</div>

        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
            <input v-model="q" :placeholder="t('customerList.search')" style="flex:1;min-width:160px">
            <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="selectAll">{{ t('customerList.selectAll') }}</button>
            <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="clearSelection">{{ t('customerList.clearSelection') }}</button>
        </div>

        <div class="seg" style="margin-bottom:10px;overflow-x:auto">
            <button :class="{ on: segment === 'all' }" @click="segment = 'all'">{{ t('customerList.segmentAll') }}</button>
            <button :class="{ on: segment === 'topSpenders' }" @click="segment = 'topSpenders'">{{ t('customerList.segmentTopSpenders') }}</button>
            <button :class="{ on: segment === 'mostVisits' }" @click="segment = 'mostVisits'">{{ t('customerList.segmentMostVisits') }}</button>
            <button :class="{ on: segment === 'highestDue' }" @click="segment = 'highestDue'">{{ t('customerList.segmentHighestDue') }}</button>
            <button :class="{ on: segment === 'inactive' }" @click="segment = 'inactive'">{{ t('customerList.segmentInactive') }}</button>
        </div>

        <div v-for="c in filtered" :key="c.id" class="row" @click="toggle(c)" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
            <div class="ava">{{ selected[c.id] ? '✅' : '👤' }}</div>
            <div class="mid">
                <b>{{ c.name }}</b>
                <span>📞 {{ c.phone || t('customerList.noPhone') }} • {{ t('customerList.totalBought') }} {{ money(c.total_spent) }}<template v-if="c.due > 0"> • <span style="color:var(--rose)">{{ t('customerList.due') }} {{ money(c.due) }}</span></template></span>
                <span v-if="segment === 'mostVisits'">🔁 {{ t('customerList.visitsCount', { n: c.visits }) }}</span>
                <span v-else-if="segment === 'inactive'" style="color:var(--rose)">⏰ {{ t('customerList.daysInactive', { n: daysSince(c.updated_at) }) }}</span>
            </div>
            <button
                type="button"
                class="btn-icon"
                style="background:none;border:none;font-size:18px;padding:4px 6px;flex-shrink:0"
                :title="c.no_marketing ? t('customerList.marketingOptedOut') : t('customerList.marketingOptIn')"
                @click.stop="toggleMarketing(c)"
            >{{ c.no_marketing ? '🔕' : '🔔' }}</button>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">👤</div>{{ t('customerList.noCustomers') }}</div>

        <div v-if="selectedCount" style="height:78px"></div>
        <div v-if="selectedCount" class="posbar" style="display:flex;gap:6px">
            <button class="btn ghost sm" style="flex:1" @click="exportSelected('xlsx')">📤 {{ t('customerList.export') }}</button>
            <button class="btn ghost sm" style="flex:1" @click="exportSelected('vcf')">📇 {{ t('customerList.exportVcard') }}</button>
            <button class="btn sm" style="flex:2" @click="openSendSheet">💬 {{ t('customerList.sendWhatsapp', { n: selectedCount }) }}</button>
        </div>

        <Sheet v-model="sendSheet" :title="t('customerList.sendSheetTitle')">
            <div class="field">
                <div class="seg">
                    <button :class="{ on: method === 'free' }" @click="method = 'free'">{{ t('customerList.methodFree') }}</button>
                    <button :class="{ on: method === 'api' }" @click="method = 'api'">{{ t('customerList.methodApi') }}</button>
                </div>
            </div>

            <template v-if="method === 'free'">
                <div class="card" style="margin-bottom:14px;font-size:12.5px;color:var(--mut)">{{ t('customerList.methodFreeHint') }}</div>
                <div class="field">
                    <label>{{ t('customerList.messageLabel') }}</label>
                    <textarea v-model="freeText" rows="4" :placeholder="t('customerList.messagePlaceholder')"></textarea>
                    <div style="font-size:11.5px;color:var(--mut);margin-top:4px">{{ t('customerList.templateVarsHint') }}</div>
                </div>
                <button class="btn wa" :disabled="!freeText.trim()" @click="startQueue">{{ t('customerList.startQueueButton', { n: selectedCount }) }}</button>
            </template>

            <template v-else>
                <div v-if="!props.whatsappApiReady" class="card" style="margin-bottom:14px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                    {{ t('customerList.methodApiNotReady') }}
                    <Link :href="route('app.more')" class="btn sm" style="margin-top:10px">{{ t('more.whatsappBusiness') }} →</Link>
                </div>
                <template v-else>
                    <div class="card" style="margin-bottom:14px;font-size:12.5px;color:var(--mut)">{{ t('customerList.methodApiHint') }}</div>
                    <div class="field">
                        <label>{{ t('customerList.messageLabel') }}</label>
                        <textarea v-model="apiForm.message" rows="4" maxlength="1000" :placeholder="t('customerList.messagePlaceholder')"></textarea>
                        <div v-if="apiForm.errors.message" style="color:var(--rose);font-size:12px;margin-top:6px">{{ apiForm.errors.message }}</div>
                    </div>
                    <div class="field">
                        <label>{{ t('customerList.imageLabel') }}</label>
                        <input type="file" accept="image/*" @change="apiForm.image = $event.target.files[0]">
                        <div v-if="apiForm.errors.image" style="color:var(--rose);font-size:12px;margin-top:6px">{{ apiForm.errors.image }}</div>
                    </div>
                    <button class="btn" :disabled="apiForm.processing || !apiForm.message.trim()" @click="sendApi">
                        {{ apiForm.processing ? '...' : t('customerList.sendButton', { n: selectedCount }) }}
                    </button>
                </template>
            </template>

            <button class="btn ghost" style="margin-top:10px" @click="sendSheet = false">{{ t('common.cancel') }}</button>
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
