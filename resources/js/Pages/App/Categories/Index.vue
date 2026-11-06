<script setup>
/**
 * Category → sub-category management (feature: sub_categories — today
 * only SEAMAN SHOE STORE). The owner's principle: a CATEGORY is what a
 * customer asks for ("show me shoes"); every kind of that thing is a
 * SUB-CATEGORY under it. Two levels, no more.
 *
 * Deleting never orphans: a category with products asks where they go
 * (another category, or "no category") before anything happens.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ tree: Array });
const { t } = useI18n();

const sheet = ref(false);
const editing = ref(null); // null = new
const form = useForm({ name: '', emoji: '', parent_id: null });

function openNew(parentId = null) {
    editing.value = null;
    form.reset();
    form.parent_id = parentId;
    sheet.value = true;
}
function openEdit(cat, parentId = null) {
    editing.value = cat;
    form.name = cat.name;
    form.emoji = cat.emoji === '📦' ? '' : cat.emoji;
    form.parent_id = parentId;
    sheet.value = true;
}
function save() {
    if (editing.value) form.patch(route('app.categories.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    else form.post(route('app.categories.store'), { onSuccess: () => { sheet.value = false; } });
}

// --- delete flow: always explicit about where the products go ---
const deleteSheet = ref(false);
const deleting = ref(null);
const moveTo = ref('');
function openDelete(cat) {
    deleting.value = cat;
    moveTo.value = '';
    deleteSheet.value = true;
}
function confirmDelete() {
    router.delete(route('app.categories.destroy', deleting.value.id), {
        data: { move_products_to: moveTo.value || null },
        onSuccess: () => { deleteSheet.value = false; },
    });
}
/** Every category EXCEPT the one being deleted — candidate destinations for its products. */
function destinations() {
    const list = [];
    for (const p of props.tree) {
        if (p.id !== deleting.value?.id) list.push(p);
        for (const c of p.children) if (c.id !== deleting.value?.id) list.push({ ...c, name: p.name + ' → ' + c.name });
    }
    return list;
}
</script>

<template>
    <Head :title="t('cat.title')" />
    <AppLayout active="stock">
        <div class="pgttl">🗂️ {{ t('cat.title') }}</div>
        <div class="pgsub">{{ t('cat.subtitle') }}</div>

        <Link :href="route('app.stock')" class="btn ghost sm" style="margin-bottom:12px;display:inline-block;text-decoration:none">← {{ t('cat.backToStock') }}</Link>
        <button class="btn" style="margin-bottom:16px" @click="openNew(null)">+ {{ t('cat.newCategory') }}</button>

        <div v-for="p in tree" :key="p.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="font-size:20px">{{ p.emoji }}</div>
                <div style="flex:1;min-width:0">
                    <b>{{ p.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ t('cat.productCount', { n: p.products_count }) }}<span v-if="p.children.length"> · {{ t('cat.subCount', { n: p.children.length }) }}</span></div>
                </div>
                <button class="btn sm ghost" style="width:auto;padding:6px 10px;font-size:12px" @click="openEdit(p, null)">{{ t('emp.edit') }}</button>
                <button class="btn sm ghost rose" style="width:auto;padding:6px 10px;font-size:12px" @click="openDelete(p)">🗑️</button>
            </div>
            <div v-for="c in p.children" :key="c.id" style="display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px 0 0 30px;border-top:1px dashed var(--line)">
                <div>{{ c.emoji }}</div>
                <div style="flex:1;min-width:0">
                    <span style="font-weight:600">{{ c.name }}</span>
                    <span style="font-size:12px;color:var(--mut)"> · {{ t('cat.productCount', { n: c.products_count }) }}</span>
                </div>
                <button class="btn sm ghost" style="width:auto;padding:5px 9px;font-size:12px" @click="openEdit(c, p.id)">{{ t('emp.edit') }}</button>
                <button class="btn sm ghost rose" style="width:auto;padding:5px 9px;font-size:12px" @click="openDelete(c)">🗑️</button>
            </div>
            <button class="btn ghost sm" style="width:auto;padding:6px 12px;margin:10px 0 0 30px;font-size:12px" @click="openNew(p.id)">+ {{ t('cat.newSub', { name: p.name }) }}</button>
        </div>
        <div v-if="!tree.length" class="empty"><div class="big">🗂️</div>{{ t('cat.none') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('emp.edit') + ' — ' + editing.name : (form.parent_id ? t('cat.newSubTitle') : t('cat.newCategory'))">
            <div class="field"><label>{{ t('cat.name') }}</label><input v-model="form.name"></div>
            <div class="field"><label>{{ t('cat.emoji') }}</label><input v-model="form.emoji" maxlength="8" placeholder="📦"></div>
            <div class="field">
                <label>{{ t('cat.parent') }}</label>
                <select v-model="form.parent_id">
                    <option :value="null">{{ t('cat.topLevel') }}</option>
                    <option v-for="p in tree.filter((x) => x.id !== editing?.id)" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                </select>
            </div>
            <div v-if="Object.keys(form.errors).length" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ Object.values(form.errors)[0] }}</div>
            <button class="btn" :disabled="form.processing" @click="save">{{ t('emp.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="deleteSheet" :title="t('cat.deleteTitle', { name: deleting?.name || '' })">
            <div v-if="deleting?.children?.length" style="color:var(--rose);font-size:13px;margin-bottom:12px">
                {{ t('cat.hasChildren') }}
            </div>
            <template v-else>
                <div v-if="deleting?.products_count" class="field">
                    <label>{{ t('cat.whereDoProductsGo', { n: deleting.products_count }) }}</label>
                    <select v-model="moveTo">
                        <option value="">{{ t('cat.noCategory') }}</option>
                        <option v-for="d in destinations()" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </div>
                <div v-else style="font-size:13px;color:var(--mut);margin-bottom:12px">{{ t('cat.emptyDeleteHint') }}</div>
                <button class="btn rose" @click="confirmDelete">{{ t('cat.confirmDelete') }}</button>
            </template>
            <button class="btn ghost" style="margin-top:10px" @click="deleteSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
