<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ guides: Array });

const showNew = ref(false);
const editingId = ref(null);
const form = useForm({ screen_key: '', label: '', text_bn: '', text_en: '' });

function openNew() {
    editingId.value = null;
    form.reset();
    showNew.value = true;
}
function openEdit(g) {
    editingId.value = g.id;
    form.label = g.label;
    form.text_bn = g.text_bn;
    form.text_en = g.text_en;
    showNew.value = true;
}
function submit() {
    if (editingId.value) {
        form.transform((data) => ({ label: data.label, text_bn: data.text_bn, text_en: data.text_en }))
            .put(route('admin.screenGuides.update', editingId.value), { onSuccess: () => { showNew.value = false; } });
    } else {
        form.post(route('admin.screenGuides.store'), { onSuccess: () => { showNew.value = false; form.reset(); } });
    }
}
function toggleActive(g) {
    router.put(route('admin.screenGuides.update', g.id), {
        label: g.label, text_bn: g.text_bn, text_en: g.text_en, is_active: !g.is_active,
    });
}
function destroy(g) {
    if (!confirm(`Delete guide "${g.label}"?`)) return;
    router.delete(route('admin.screenGuides.destroy', g.id));
}
</script>

<template>
    <Head title="Screen Guides" />
    <AdminLayout active="screenGuides">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">Screen Guides</h1>
                <p class="text-sm text-gray-500 mt-1">The "📖 How do I do this?" hint shown on specific app screens. screen_key must match the key a Vue page passes to &lt;HowToHint screen-key="..."&gt; — a screen with no guide set here simply shows nothing.</p>
            </div>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="openNew">+ New guide</button>
        </div>

        <form v-if="showNew" @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-6 space-y-3 max-w-2xl">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div v-if="!editingId">
                    <label class="text-xs font-medium text-gray-500">screen_key (matches the Vue page, e.g. "pos")</label>
                    <input v-model="form.screen_key" class="rounded-lg border-gray-300 text-sm w-full mt-1 font-mono">
                    <div v-if="form.errors.screen_key" class="text-rose-600 text-xs mt-1">{{ form.errors.screen_key }}</div>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Label (admin-only, not shown to shop users)</label>
                    <input v-model="form.label" class="rounded-lg border-gray-300 text-sm w-full mt-1">
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium text-gray-500">Text (বাংলা)</label>
                    <textarea v-model="form.text_bn" rows="3" class="rounded-lg border-gray-300 text-sm w-full mt-1"></textarea>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500">Text (English)</label>
                    <textarea v-model="form.text_en" rows="3" class="rounded-lg border-gray-300 text-sm w-full mt-1"></textarea>
                </div>
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
                    <tr><th class="text-left px-4 py-3">screen_key</th><th class="text-left px-4 py-3">Label</th><th class="text-left px-4 py-3">Active</th><th class="text-right px-4 py-3">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="g in guides" :key="g.id" class="border-t">
                        <td class="px-4 py-3 font-mono text-xs">{{ g.screen_key }}</td>
                        <td class="px-4 py-3">{{ g.label }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="g.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                {{ g.is_active ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-3">
                            <button class="text-xs font-semibold text-violet-600" @click="openEdit(g)">Edit</button>
                            <button class="text-xs font-semibold" :class="g.is_active ? 'text-rose-600' : 'text-emerald-600'" @click="toggleActive(g)">
                                {{ g.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                            <button class="text-xs font-semibold text-gray-400" @click="destroy(g)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!guides.length" class="p-6 text-center text-gray-400 text-sm">No guides yet.</div>
        </div>
    </AdminLayout>
</template>
