<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ backups: Array });
const { t } = useI18n();

const requesting = ref(null);

function request(format) {
    requesting.value = format;
    router.post(route('app.shopBackups.store'), { format }, {
        preserveScroll: true,
        onFinish: () => (requesting.value = null),
    });
}

function removeBackup(b) {
    if (!confirm(t('backup.removeConfirm'))) return;
    router.delete(route('app.shopBackups.destroy', b.id), { preserveScroll: true });
}

const STATUS_LABEL = {
    pending: 'backup.statusPending',
    processing: 'backup.statusProcessing',
    done: 'backup.statusDone',
    failed: 'backup.statusFailed',
};
const STATUS_CLASS = {
    pending: 'mint', processing: 'gold', done: 'mint', failed: 'rose',
};

function fmtDate(iso) {
    return new Date(iso).toLocaleString('bn-BD', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head :title="t('backup.title')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('backup.title') }}</div>
        <div class="pgsub">{{ t('backup.subtitle') }}</div>

        <div class="card" style="margin-bottom:16px;font-size:13px;color:var(--mut);line-height:1.7">
            {{ t('backup.explain') }}
        </div>

        <div class="btnrow" style="margin-bottom:20px">
            <button class="btn sm" style="flex:1" :disabled="requesting" @click="request('sql')">
                {{ requesting === 'sql' ? '...' : t('backup.requestSql') }}
            </button>
            <button class="btn ghost sm" style="flex:1" :disabled="requesting" @click="request('xlsx')">
                {{ requesting === 'xlsx' ? '...' : t('backup.requestExcel') }}
            </button>
        </div>

        <div class="sechead"><h2>{{ t('backup.historyTitle') }}</h2></div>
        <div v-for="b in backups" :key="b.id" class="row">
            <div class="ava">{{ b.format === 'sql' ? '🗄️' : '📊' }}</div>
            <div class="mid">
                <b>{{ b.format.toUpperCase() }} {{ t('backup.backupWord') }}</b>
                <span>{{ fmtDate(b.created_at) }}<template v-if="b.requested_by"> • {{ b.requested_by }}</template></span>
                <span v-if="b.status === 'failed' && b.failed_reason" style="color:var(--rose)">{{ b.failed_reason }}</span>
            </div>
            <div class="end" style="display:flex;align-items:center;gap:8px">
                <span class="pill" :class="STATUS_CLASS[b.status]">{{ t(STATUS_LABEL[b.status]) }}</span>
                <a v-if="b.status === 'done'" :href="route('app.shopBackups.download', b.id)" class="btn ghost sm">{{ t('backup.download') }}</a>
                <button class="btn ghost sm" @click="removeBackup(b)">🗑️</button>
            </div>
        </div>
        <div v-if="!backups.length" class="empty"><div class="big">🗄️</div>{{ t('backup.noneYet') }}</div>
    </AppLayout>
</template>
