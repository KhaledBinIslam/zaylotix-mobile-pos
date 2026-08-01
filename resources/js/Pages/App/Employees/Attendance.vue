<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ date: String, employees: Array });
const { t } = useI18n();

const selectedDate = ref(props.date);
function changeDate() {
    router.get(route('app.attendance.index'), { date: selectedDate.value }, { preserveState: true });
}

const STATUS_META = {
    present: { label: 'att.present', cls: 'mint' },
    absent: { label: 'att.absent', cls: 'rose' },
    leave: { label: 'att.leave', cls: 'gold' },
    half_day: { label: 'att.halfDay', cls: 'sky' },
};

function mark(employeeId, status) {
    router.post(route('app.attendance.mark'), { date: selectedDate.value, employee_id: employeeId, status }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('att.title')" />
    <AppLayout active="employees">
        <div class="pgttl">{{ t('att.title') }}</div>
        <div class="pgsub">{{ t('att.subtitle') }}</div>

        <input v-model="selectedDate" type="date" style="margin-bottom:16px" @change="changeDate">

        <div v-for="e in employees" :key="e.id" class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div>
                    <b>{{ e.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ e.designation || '-' }}</div>
                </div>
                <span class="pill" :class="STATUS_META[e.status].cls">{{ t(STATUS_META[e.status].label) }}</span>
            </div>
            <div class="seg">
                <button :class="{ on: e.status === 'present' }" @click="mark(e.id, 'present')">{{ t('att.present') }}</button>
                <button :class="{ on: e.status === 'absent' }" @click="mark(e.id, 'absent')">{{ t('att.absent') }}</button>
                <button :class="{ on: e.status === 'leave' }" @click="mark(e.id, 'leave')">{{ t('att.leave') }}</button>
                <button :class="{ on: e.status === 'half_day' }" @click="mark(e.id, 'half_day')">{{ t('att.halfDay') }}</button>
            </div>
        </div>
        <div v-if="!employees.length" class="empty"><div class="big">👥</div>{{ t('att.noActiveEmployees') }}</div>
    </AppLayout>
</template>
