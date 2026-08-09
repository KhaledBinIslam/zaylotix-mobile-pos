<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ units: Array });
const { t } = useI18n();

const editSheet = ref(false);
const editing = ref(null); // null = adding a new one, otherwise the unit being renamed
const form = useForm({ name: '', name_en: '', code: '' });

function openAdd() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    editSheet.value = true;
}
function openEdit(u) {
    editing.value = u;
    form.name = u.name;
    form.name_en = u.name_en;
    form.code = u.code || '';
    form.clearErrors();
    editSheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.units.update', editing.value.id), { onSuccess: () => (editSheet.value = false) });
    } else {
        form.post(route('app.units.store'), { onSuccess: () => (editSheet.value = false) });
    }
}
function remove(u) {
    if (!confirm(`"${u.name}" ${t('unit.removeConfirm')}`)) return;
    form.delete(route('app.units.destroy', u.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('unit.title')" />
    <AppLayout active="units">
        <div class="pgttl">{{ t('unit.title') }}</div>
        <div class="pgsub">{{ t('unit.subtitle') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openAdd">+ {{ t('unit.add') }}</button>

        <div v-if="form.errors.unit" class="card" style="background:var(--roseSoft);color:var(--rose);margin-bottom:14px;font-size:13px">{{ form.errors.unit }}</div>

        <div v-for="u in units" :key="u.id" class="row">
            <div class="ava">{{ u.code || u.name[0] }}</div>
            <div class="mid">
                <b>{{ u.name }}</b>
                <span>{{ u.name_en }}<template v-if="u.in_use"> • {{ t('unit.inUse', { n: u.in_use }) }}</template></span>
            </div>
            <div class="end" style="display:flex;gap:6px">
                <button class="btn sm ghost" style="width:auto" @click="openEdit(u)">✎</button>
                <button class="btn sm rose" style="width:auto" @click="remove(u)">✕</button>
            </div>
        </div>
        <div v-if="!units.length" class="empty"><div class="big">📏</div>{{ t('unit.none') }}</div>

        <Sheet v-model="editSheet" :title="editing ? t('unit.editTitle') : t('unit.addTitle')">
            <div class="field">
                <label>{{ t('unit.nameBn') }}</label>
                <input v-model="form.name" :placeholder="t('unit.nameBnPlaceholder')">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('unit.nameEn') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.name_en" :placeholder="t('unit.nameEnPlaceholder')">
            </div>
            <div class="field">
                <label>{{ t('unit.code') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.code" :placeholder="t('unit.codePlaceholder')">
            </div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
        </Sheet>
    </AppLayout>
</template>
