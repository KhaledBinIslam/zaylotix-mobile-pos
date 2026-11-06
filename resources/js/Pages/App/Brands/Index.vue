<script setup>
/**
 * Brand management (feature: brands — SEAMAN Phase 4). Add / rename /
 * merge / delete. Merge is first-class because a hand-copied catalogue
 * breeds near-duplicates; delete just turns products' brand into "Nil"
 * (the owner's display rule for anything missing), stated in the
 * confirm text.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ brands: Array });
const { t } = useI18n();

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '' });

function openNew() {
    editing.value = null;
    form.reset();
    sheet.value = true;
}
function openEdit(b) {
    editing.value = b;
    form.name = b.name;
    sheet.value = true;
}
function save() {
    if (editing.value) form.patch(route('app.brands.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    else form.post(route('app.brands.store'), { onSuccess: () => { sheet.value = false; } });
}

const mergeSheet = ref(false);
const merging = ref(null);
const mergeInto = ref('');
function openMerge(b) {
    merging.value = b;
    mergeInto.value = '';
    mergeSheet.value = true;
}
function confirmMerge() {
    router.post(route('app.brands.merge', merging.value.id), { into: mergeInto.value }, {
        onSuccess: () => { mergeSheet.value = false; },
    });
}
function destroy(b) {
    if (confirm(t('brand.deleteConfirm', { name: b.name }))) router.delete(route('app.brands.destroy', b.id));
}
</script>

<template>
    <Head :title="t('brand.title')" />
    <AppLayout active="stock">
        <div class="pgttl">🏭 {{ t('brand.title') }}</div>
        <div class="pgsub">{{ t('brand.subtitle') }}</div>
        <HowToHint :text="t('brand.howTo')" />

        <Link :href="route('app.stock')" class="btn ghost sm" style="margin-bottom:12px;display:inline-block;text-decoration:none">← {{ t('cat.backToStock') }}</Link>
        <button class="btn" style="margin-bottom:16px" @click="openNew">+ {{ t('brand.new') }}</button>

        <div v-for="b in brands" :key="b.id" class="card" style="margin-bottom:8px;display:flex;align-items:center;gap:10px">
            <div style="flex:1;min-width:0">
                <b>{{ b.name }}</b>
                <div style="font-size:12px;color:var(--mut)">{{ t('brand.usage', { p: b.products_count, v: b.variants_count }) }}</div>
            </div>
            <button class="btn sm ghost" style="width:auto;padding:6px 10px;font-size:12px" @click="openEdit(b)">{{ t('emp.edit') }}</button>
            <button v-if="brands.length > 1" class="btn sm ghost" style="width:auto;padding:6px 10px;font-size:12px" @click="openMerge(b)">{{ t('brand.merge') }}</button>
            <button class="btn sm ghost rose" style="width:auto;padding:6px 10px;font-size:12px" @click="destroy(b)">🗑️</button>
        </div>
        <div v-if="!brands.length" class="empty"><div class="big">🏭</div>{{ t('brand.none') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('emp.edit') + ' — ' + editing.name : t('brand.new')">
            <div class="field"><label>{{ t('cat.name') }}</label><input v-model="form.name" placeholder="Lotus"></div>
            <div v-if="form.errors.name" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ form.errors.name }}</div>
            <button class="btn" :disabled="form.processing" @click="save">{{ t('emp.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="mergeSheet" :title="t('brand.mergeTitle', { name: merging?.name || '' })">
            <div style="font-size:13px;color:var(--mut);margin-bottom:12px">{{ t('brand.mergeHint') }}</div>
            <div class="field">
                <label>{{ t('brand.mergeInto') }}</label>
                <select v-model="mergeInto">
                    <option value="" disabled>{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="b in brands.filter((x) => x.id !== merging?.id)" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
            <button class="btn" :disabled="!mergeInto" @click="confirmMerge">{{ t('brand.mergeDo') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="mergeSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
