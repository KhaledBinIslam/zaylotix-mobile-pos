<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { reportClientError } from '@/support/reportClientError';

const props = defineProps({ orders: Object, channels: Array, status: String, channelId: [Number, String], statuses: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const statusPill = {
    pending: 'gold', confirmed: 'gold', packed: 'gold', handed_to_courier: 'gold', in_transit: 'gold',
    delivered: 'mint', failed: 'rose', returning: 'rose', returned: 'rose', cancelled: 'mut',
};

function applyFilter(e) {
    router.get(route('app.onlineOrders.index'), {
        status: document.getElementById('statusFilter').value,
        channel_id: document.getElementById('channelFilter').value,
    }, { preserveState: true });
}

// --- CSV import (see docs/FIX_PLAN.md's Phase 32 design note — one
// product/qty per row, a multi-item order still goes through the manual
// form) ---
const csvSheet = ref(false);
const csvChannelId = ref(props.channels[0]?.id || '');
const csvFile = ref(null);
const csvRows = ref(null);
const csvPreviewing = ref(false);
const csvImporting = ref(false);

function openCsvSheet() {
    csvRows.value = null;
    csvFile.value = null;
    csvSheet.value = true;
}
function pickCsvFile(e) {
    csvFile.value = e.target.files[0] || null;
}
function previewCsv() {
    if (!csvFile.value || !csvChannelId.value) return;
    csvPreviewing.value = true;
    const formData = new FormData();
    formData.append('channel_id', csvChannelId.value);
    formData.append('file', csvFile.value);
    fetch(route('app.onlineOrders.csvPreview'), {
        method: 'POST', body: formData,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
    })
        .then((r) => { if (!r.ok) throw new Error(`csvPreview responded ${r.status}`); return r.json(); })
        .then((data) => { csvRows.value = data.rows; })
        .catch((e) => { reportClientError(e, 'csvPreview'); toast('❌ ' + t('onlineOrder.csvPreviewFailed')); })
        .finally(() => (csvPreviewing.value = false));
}
const csvOkCount = () => (csvRows.value || []).filter((r) => r.ok).length;
function confirmImport() {
    csvImporting.value = true;
    useForm({
        channel_id: csvChannelId.value,
        rows: (csvRows.value || []).filter((r) => r.ok),
    }).post(route('app.onlineOrders.csvImport'), {
        onSuccess: () => { csvSheet.value = false; csvImporting.value = false; },
        onError: () => (csvImporting.value = false),
    });
}
</script>

<template>
    <Head :title="t('nav.onlineOrders')" />
    <AppLayout active="onlineOrders">
        <div class="pgttl">{{ t('nav.onlineOrders') }}</div>
        <div class="pgsub">{{ t('onlineOrder.subtitle') }}</div>

        <div class="btnrow" style="margin-bottom:16px">
            <Link :href="route('app.onlineOrders.create')" class="btn ghost sm" style="flex:1;text-align:center;text-decoration:none">{{ t('onlineOrder.add') }}</Link>
            <button class="btn ghost sm" style="flex:1" @click="openCsvSheet">{{ t('onlineOrder.csvImport') }}</button>
        </div>

        <div class="f2" style="margin-bottom:14px">
            <select id="statusFilter" :value="status" @change="applyFilter">
                <option value="">{{ t('onlineOrder.allStatuses') }}</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ t('onlineOrder.status.' + s) }}</option>
            </select>
            <select id="channelFilter" :value="channelId || ''" @change="applyFilter">
                <option value="">{{ t('onlineOrder.allChannels') }}</option>
                <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </div>

        <Link v-for="o in orders.data" :key="o.id" :href="route('app.onlineOrders.show', o.id)" class="row" style="text-decoration:none;color:inherit">
            <div class="ava">🌐</div>
            <div class="mid">
                <b>{{ o.order_number }}</b>
                <span>{{ o.channel.name }} • {{ o.sale?.customer?.name || t('home.cashCustomer') }} • {{ money(o.sale?.total || 0) }}</span>
            </div>
            <div class="end"><span class="pill" :class="statusPill[o.status]">{{ t('onlineOrder.status.' + o.status) }}</span></div>
        </Link>
        <div v-if="!orders.data.length" class="empty"><div class="big">🌐</div>{{ t('onlineOrder.noOrders') }}</div>

        <Pagination :links="orders.links" />

        <Sheet v-model="csvSheet" :title="t('onlineOrder.csvImport')" :subtitle="t('onlineOrder.csvImportSubtitle')">
            <div class="field">
                <label>{{ t('channel.title') }}</label>
                <select v-model="csvChannelId">
                    <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('onlineOrder.csvFile') }}</label>
                <input type="file" accept=".csv,text/csv" @change="pickCsvFile">
                <div style="font-size:11.5px;color:var(--mut);margin-top:4px">{{ t('onlineOrder.csvColumnsHint') }}</div>
            </div>
            <button v-if="!csvRows" class="btn" :disabled="!csvFile || csvPreviewing" @click="previewCsv">{{ csvPreviewing ? '...' : t('onlineOrder.csvPreviewButton') }}</button>

            <template v-if="csvRows">
                <div class="card" style="margin:12px 0;font-size:13px">
                    {{ t('onlineOrder.csvPreviewSummary', { ok: csvOkCount(), total: csvRows.length }) }}
                </div>
                <div v-for="r in csvRows" :key="r.row" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:12.5px">
                    <span>#{{ r.row }} — {{ r.phone }} — {{ r.product_name || r.product_query }} × {{ r.qty }}</span>
                    <span :style="r.ok ? 'color:var(--green)' : 'color:var(--rose)'">{{ r.ok ? '✅' : '❌ ' + r.error }}</span>
                </div>
                <button class="btn" style="margin-top:14px" :disabled="csvOkCount() === 0 || csvImporting" @click="confirmImport">
                    {{ csvImporting ? '...' : t('onlineOrder.csvConfirmImport', { n: csvOkCount() }) }}
                </button>
                <button class="btn ghost" style="margin-top:10px" @click="csvRows = null">{{ t('common.cancel') }}</button>
            </template>
        </Sheet>
    </AppLayout>
</template>
