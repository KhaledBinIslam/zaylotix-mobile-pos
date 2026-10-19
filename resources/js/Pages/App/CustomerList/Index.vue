<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import {
    buildQueueTargets,
    currentTarget,
    initQueueState,
    isQueueDone,
    markCurrent,
    renderTemplate,
    sameTargetSet,
    sentCount,
    shouldShowPauseReminder,
    skippedCount,
    stateFromItems,
    waLink,
} from '@/support/whatsappQueue';

const props = defineProps({ customers: Array, whatsappApiReady: Boolean });
const { t } = useI18n();
const { toast } = useToast();
const page = usePage();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
// Missing feature (see docs/FIX_PLAN.md Phase 7): this was a flat,
// undifferentiated list with search only — the underlying data
// (total_spent, visits, due, updated_at-as-last-activity) already existed
// on Customer, just never exposed as a sort/filter. 'all' keeps the exact
// previous behavior (alphabetical, no filtering) unchanged.
const segment = ref('all');
const INACTIVE_DAYS = 30;
function daysSince(dateStr) {
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000);
}
const filtered = computed(() => {
    let rows = props.customers.filter((c) =>
        !q.value || c.name.toLowerCase().includes(q.value.toLowerCase()) || (c.phone || '').includes(q.value)
    );
    if (segment.value === 'topSpenders') rows = [...rows].sort((a, b) => b.total_spent - a.total_spent);
    else if (segment.value === 'mostVisits') rows = [...rows].sort((a, b) => b.visits - a.visits);
    else if (segment.value === 'highestDue') rows = rows.filter((c) => c.due > 0).sort((a, b) => b.due - a.due);
    else if (segment.value === 'inactive') rows = rows.filter((c) => daysSince(c.updated_at) >= INACTIVE_DAYS).sort((a, b) => daysSince(b.updated_at) - daysSince(a.updated_at));
    return rows;
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

// ---------------- "পরবর্তী জনকে পাঠান" queue — one wa.me tab per direct
// tap, resumable across app restarts (localStorage, scoped per shop+user
// so two staff members on the same device never collide) ----------------
const queueSheet = ref(false);
const queueState = ref(null);
const queueSkippedInvalid = ref([]);

const storageKey = computed(() => `zaylotix_wa_queue_cl_${page.props.shop?.id ?? 0}_${page.props.auth?.user?.id ?? 0}`);

function loadSavedQueue() {
    try {
        const raw = localStorage.getItem(storageKey.value);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}
function saveQueue() {
    try {
        if (!queueState.value) {
            localStorage.removeItem(storageKey.value);
            return;
        }
        localStorage.setItem(storageKey.value, JSON.stringify({ items: queueState.value.items, template: freeText.value }));
    } catch {
        // localStorage can throw (private mode, quota) — resuming is a
        // convenience, not a correctness requirement, so this stays silent.
    }
}

function startQueue() {
    if (!freeText.value.trim()) {
        toast('❌ ' + t('customerList.writeMessageError'));
        return;
    }
    const chosen = props.customers.filter((c) => selected.value[c.id]);
    const built = buildQueueTargets(chosen);
    if (!built.targets.length) {
        toast('❌ ' + t('customerList.noPhoneSelected'));
        return;
    }
    queueSkippedInvalid.value = built.skippedInvalid;

    const saved = loadSavedQueue();
    if (saved && sameTargetSet(saved.items, built.targets) && saved.items.some((i) => i.status === 'pending')) {
        queueState.value = stateFromItems(saved.items);
        if (saved.template) freeText.value = saved.template;
        toast('↩️ ' + t('customerList.resumedQueue'));
    } else {
        queueState.value = initQueueState(built.targets);
    }
    saveQueue();
    sendSheet.value = false;
    queueSheet.value = true;
}

const queueCurrent = computed(() => (queueState.value ? currentTarget(queueState.value) : null));
const queueSent = computed(() => (queueState.value ? sentCount(queueState.value) : 0));
const queueSkipped = computed(() => (queueState.value ? skippedCount(queueState.value) : 0));
const queueTotal = computed(() => (queueState.value ? queueState.value.items.length : 0));
const queueDone = computed(() => (queueState.value ? isQueueDone(queueState.value) : false));
const showPauseReminder = computed(() => queueState.value && shouldShowPauseReminder(queueSent.value));

/** The one and only window.open() in this whole queue flow — called directly from the "পরবর্তী জনকে পাঠান" button's own click, so it always carries a real user gesture. */
function sendToCurrent() {
    const target = queueCurrent.value;
    if (!target) return;
    const text = renderTemplate(freeText.value, target, page.props.shop?.name);
    window.open(waLink(target.phone, text), '_blank');
    queueState.value = markCurrent(queueState.value, 'sent');
    saveQueue();
}
function skipCurrent() {
    if (!queueCurrent.value) return;
    queueState.value = markCurrent(queueState.value, 'skipped');
    saveQueue();
}
function closeQueue() {
    if (queueDone.value) saveQueue();
    queueSheet.value = false;
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

        <Sheet v-model="queueSheet" :title="t('customerList.queueSheetTitle')">
            <div v-if="queueSkippedInvalid.length" class="card" style="margin-bottom:12px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                {{ t('customerList.queueSkippedInvalid', { n: queueSkippedInvalid.length }) }}
            </div>

            <div class="card" style="margin-bottom:12px;font-size:12.5px;color:var(--mut)">{{ t('customerList.queueSpamWarning') }}</div>

            <div v-if="showPauseReminder" class="card" style="margin-bottom:12px;background:var(--goldSoft);font-size:12.5px">
                ⏸️ {{ t('customerList.queuePauseReminder') }}
            </div>

            <div style="font-size:13px;color:var(--mut);margin-bottom:10px">{{ t('customerList.queueProgress', { sent: queueSent, total: queueTotal }) }}</div>

            <template v-if="!queueDone">
                <div class="card" style="margin-bottom:14px">
                    <b>{{ queueCurrent?.name }}</b>
                    <div style="font-size:13px;color:var(--mut)">📞 {{ queueCurrent?.phone }}</div>
                </div>
                <button class="btn wa" style="margin-bottom:8px" @click="sendToCurrent">
                    💬 {{ t('customerList.queueSendNext', { current: queueSent + queueSkipped + 1, total: queueTotal }) }}
                </button>
                <button class="btn ghost sm" style="margin-bottom:8px" @click="skipCurrent">{{ t('customerList.queueSkip') }}</button>
            </template>
            <div v-else class="card" style="margin-bottom:14px;background:var(--goldSoft)">
                ✅ {{ t('customerList.queueDoneSummary', { sent: queueSent, skipped: queueSkipped }) }}
            </div>

            <button class="btn ghost" @click="closeQueue">{{ queueDone ? t('common.close') : t('customerList.queueStop') }}</button>
        </Sheet>
    </AppLayout>
</template>
