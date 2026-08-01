<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ businessTypes: Array });

const showNew = ref(false);
const form = useForm({ slug: '', label_bn: '', label_en: '', fields: [] });
function submit() {
    form.post(route('admin.businessTypes.store'), { onSuccess: () => { showNew.value = false; form.reset(); } });
}

const fieldOptions = ['expiry', 'batch', 'imei', 'size', 'color'];

function toggleActive(t) {
    router.put(route('admin.businessTypes.update', t.id), {
        label_bn: t.label_bn,
        label_en: t.label_en,
        fields: t.fields || [],
        is_active: !t.is_active,
    });
}
</script>

<template>
    <Head title="Business Types" />
    <AdminLayout active="businessTypes">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Business Types</h1>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="showNew = !showNew">+ New type</button>
        </div>

        <form v-if="showNew" @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-6 space-y-3 max-w-lg">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <input v-model="form.slug" placeholder="slug (e.g. bakery)" class="rounded-lg border-gray-300 text-sm w-full">
                    <div v-if="form.errors.slug" class="text-rose-600 text-xs mt-1">{{ form.errors.slug }}</div>
                </div>
                <input v-model="form.label_bn" placeholder="বাংলা নাম" class="rounded-lg border-gray-300 text-sm">
                <input v-model="form.label_en" placeholder="English label" class="rounded-lg border-gray-300 text-sm">
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                <label v-for="f in fieldOptions" :key="f" class="flex items-center gap-1">
                    <input type="checkbox" :value="f" v-model="form.fields"> {{ f }}
                </label>
            </div>
            <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Save' }}
            </button>
        </form>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr><th class="text-left px-4 py-3">Slug</th><th class="text-left px-4 py-3">বাংলা</th><th class="text-left px-4 py-3">English</th><th class="text-left px-4 py-3">Fields</th><th class="text-left px-4 py-3">Active</th><th class="text-right px-4 py-3">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-for="t in businessTypes" :key="t.id" class="border-t">
                        <td class="px-4 py-3 font-mono text-xs">{{ t.slug }}</td>
                        <td class="px-4 py-3">{{ t.label_bn }}</td>
                        <td class="px-4 py-3">{{ t.label_en }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ (t.fields || []).join(', ') || '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="t.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                {{ t.is_active ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button class="text-xs font-semibold" :class="t.is_active ? 'text-rose-600' : 'text-emerald-600'" @click="toggleActive(t)">
                                {{ t.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
