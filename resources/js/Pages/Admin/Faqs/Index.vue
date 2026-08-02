<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ faqs: Array });

const showNew = ref(false);
const editingId = ref(null);
const form = useForm({ question_bn: '', question_en: '', answer_bn: '', answer_en: '', sort_order: 0 });

function openNew() {
    editingId.value = null;
    form.reset();
    showNew.value = true;
}
function openEdit(f) {
    editingId.value = f.id;
    form.question_bn = f.question_bn;
    form.question_en = f.question_en;
    form.answer_bn = f.answer_bn;
    form.answer_en = f.answer_en;
    form.sort_order = f.sort_order;
    showNew.value = true;
}
function submit() {
    if (editingId.value) {
        form.put(route('admin.faqs.update', editingId.value), { onSuccess: () => { showNew.value = false; } });
    } else {
        form.post(route('admin.faqs.store'), { onSuccess: () => { showNew.value = false; form.reset(); } });
    }
}
function toggleActive(f) {
    router.put(route('admin.faqs.update', f.id), {
        question_bn: f.question_bn, question_en: f.question_en, answer_bn: f.answer_bn, answer_en: f.answer_en,
        sort_order: f.sort_order, is_active: !f.is_active,
    });
}
function destroy(f) {
    if (!confirm(`Delete FAQ "${f.question_en}"?`)) return;
    router.delete(route('admin.faqs.destroy', f.id));
}
</script>

<template>
    <Head title="FAQs" />
    <AdminLayout active="faqs">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">FAQs</h1>
                <p class="text-sm text-gray-500 mt-1">Shown in every shop's Help page (App → ❓ → FAQ) — same list for every shop.</p>
            </div>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="openNew">+ New FAQ</button>
        </div>

        <form v-if="showNew" @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-6 space-y-3 max-w-2xl">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium text-gray-500">Question (বাংলা)</label>
                    <input v-model="form.question_bn" class="rounded-lg border-gray-300 text-sm w-full mt-1">
                    <div v-if="form.errors.question_bn" class="text-rose-600 text-xs mt-1">{{ form.errors.question_bn }}</div>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Question (English)</label>
                    <input v-model="form.question_en" class="rounded-lg border-gray-300 text-sm w-full mt-1">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium text-gray-500">Answer (বাংলা)</label>
                    <textarea v-model="form.answer_bn" rows="3" class="rounded-lg border-gray-300 text-sm w-full mt-1"></textarea>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Answer (English)</label>
                    <textarea v-model="form.answer_en" rows="3" class="rounded-lg border-gray-300 text-sm w-full mt-1"></textarea>
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500">Sort order (lower shows first)</label>
                <input v-model.number="form.sort_order" type="number" class="rounded-lg border-gray-300 text-sm w-32 mt-1">
            </div>
            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </button>
                <button type="button" class="px-4 py-2 rounded-lg border text-sm" @click="showNew = false">Cancel</button>
            </div>
        </form>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr><th class="text-left px-4 py-3">#</th><th class="text-left px-4 py-3">Question (EN)</th><th class="text-left px-4 py-3">Active</th><th class="text-right px-4 py-3">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="f in faqs" :key="f.id" class="border-t">
                        <td class="px-4 py-3 text-xs text-gray-400">{{ f.sort_order }}</td>
                        <td class="px-4 py-3">{{ f.question_en }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="f.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                {{ f.is_active ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3">
                            <button class="text-xs font-semibold text-violet-600" @click="openEdit(f)">Edit</button>
                            <button class="text-xs font-semibold" :class="f.is_active ? 'text-rose-600' : 'text-emerald-600'" @click="toggleActive(f)">
                                {{ f.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                            <button class="text-xs font-semibold text-gray-400" @click="destroy(f)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!faqs.length" class="p-6 text-center text-gray-400 text-sm">No FAQs yet.</div>
        </div>
    </AdminLayout>
</template>
