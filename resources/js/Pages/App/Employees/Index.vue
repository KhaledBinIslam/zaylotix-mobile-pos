<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ employees: Array });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', phone: '', designation: '', salary_type: 'monthly', basic_salary: '', joining_date: new Date().toISOString().slice(0, 10), status: 'active' });

function openNew() {
    editing.value = null;
    form.reset();
    form.joining_date = new Date().toISOString().slice(0, 10);
    sheet.value = true;
}
function openEdit(e) {
    editing.value = e;
    form.name = e.name;
    form.phone = e.phone || '';
    form.designation = e.designation || '';
    form.salary_type = e.salary_type;
    form.basic_salary = e.basic_salary;
    form.status = e.status;
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.employees.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    } else {
        form.post(route('app.employees.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
    }
}

const advanceSheet = ref(false);
const advancingEmployee = ref(null);
const advanceForm = useForm({ amount: '', method: 'cash', note: '' });
function openAdvance(e) {
    advancingEmployee.value = e;
    advanceForm.reset();
    advanceSheet.value = true;
}
function submitAdvance() {
    router.post(route('app.employees.advances.store', advancingEmployee.value.id), advanceForm.data(), {
        onSuccess: () => { advanceSheet.value = false; },
    });
}
</script>

<template>
    <Head :title="t('emp.title')" />
    <AppLayout active="employees">
        <div class="pgttl">{{ t('emp.title') }}</div>
        <div class="pgsub">{{ t('emp.subtitle') }}</div>

        <button class="btn" style="margin-bottom:16px" @click="openNew">{{ t('emp.addEmployee') }}</button>

        <div v-for="e in employees" :key="e.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <b style="font-size:15px">{{ e.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ e.designation || '-' }} • {{ e.phone || '-' }}</div>
                    <div style="font-size:12.5px;margin-top:4px">
                        {{ e.salary_type === 'monthly' ? t('emp.monthly') : t('emp.daily') }} — <b>{{ money(e.basic_salary) }}</b>
                    </div>
                    <span v-if="e.status === 'inactive'" class="pill rose" style="margin-top:6px;display:inline-block">{{ t('emp.inactive') }}</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <button class="btn sm ghost" style="width:auto;padding:6px 12px;font-size:12px" @click="openEdit(e)">{{ t('emp.edit') }}</button>
                    <button v-if="e.status === 'active'" class="btn sm ghost" style="width:auto;padding:6px 12px;font-size:12px" @click="openAdvance(e)">{{ t('emp.giveAdvance') }}</button>
                </div>
            </div>
        </div>
        <div v-if="!employees.length" class="empty"><div class="big">👥</div>{{ t('emp.noEmployees') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('emp.edit') : t('emp.addEmployee')">
            <div class="field"><label>{{ t('emp.name') }}</label><input v-model="form.name"></div>
            <div class="field"><label>{{ t('emp.phone') }}</label><input v-model="form.phone"></div>
            <div class="field"><label>{{ t('emp.designation') }}</label><input v-model="form.designation"></div>
            <div class="field">
                <label>{{ t('emp.salaryType') }}</label>
                <div class="seg">
                    <button :class="{ on: form.salary_type === 'monthly' }" @click="form.salary_type = 'monthly'">{{ t('emp.monthly') }}</button>
                    <button :class="{ on: form.salary_type === 'daily' }" @click="form.salary_type = 'daily'">{{ t('emp.daily') }}</button>
                </div>
            </div>
            <div class="field">
                <label>{{ t('emp.basicSalary') }}</label>
                <input v-model="form.basic_salary" type="number">
                <div v-if="form.salary_type === 'daily'" style="font-size:12px;color:var(--mut);margin-top:4px">{{ t('emp.basicSalaryDailyHint') }}</div>
                <div v-if="form.errors.basic_salary" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.basic_salary }}</div>
            </div>
            <div v-if="!editing" class="field"><label>{{ t('emp.joiningDate') }}</label><input v-model="form.joining_date" type="date"></div>
            <div v-if="editing" class="field">
                <label>{{ t('emp.status') }}</label>
                <div class="seg">
                    <button :class="{ on: form.status === 'active' }" @click="form.status = 'active'">{{ t('emp.active') }}</button>
                    <button :class="{ on: form.status === 'inactive' }" @click="form.status = 'inactive'">{{ t('emp.inactive') }}</button>
                </div>
            </div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('emp.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="advanceSheet" :title="t('emp.giveAdvance')">
            <div class="field">
                <label>{{ t('emp.advanceAmount') }}</label>
                <input v-model="advanceForm.amount" type="number">
                <div v-if="advanceForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ advanceForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('emp.method') }}</label>
                <select v-model="advanceForm.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
            </div>
            <div class="field"><label>{{ t('partner.note') }}</label><input v-model="advanceForm.note"></div>
            <button class="btn" :disabled="advanceForm.processing" @click="submitAdvance">{{ advanceForm.processing ? '...' : t('emp.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="advanceSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
