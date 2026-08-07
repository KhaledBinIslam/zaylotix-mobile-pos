<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ features: Array, recommendedFor: Object });

const showNew = ref(false);
const form = useForm({ key: '', label_bn: '', label_en: '', category: 'general', description: '', monthly_price: 0 });
function submit() {
    form.post(route('admin.features.store'), { onSuccess: () => { showNew.value = false; form.reset(); } });
}

function toggleActive(f) {
    router.put(route('admin.features.update', f.id), {
        label_bn: f.label_bn, label_en: f.label_en, category: f.category, description: f.description,
        is_active: !f.is_active, monthly_price: f.monthly_price,
    });
}

// price is edited inline, right in the table — saved on blur so ticking
// through a dozen features doesn't mean a dozen separate "save" clicks
function savePrice(f, value) {
    router.put(route('admin.features.update', f.id), {
        label_bn: f.label_bn, label_en: f.label_en, category: f.category, description: f.description,
        is_active: f.is_active, monthly_price: value,
    }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Features" />
    <AdminLayout active="features">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">Features</h1>
                <p class="text-sm text-gray-500 mt-1">The permission catalog — grant these per shop from its Create/Edit form. New rows here become available to grant immediately, no code changes needed.</p>
            </div>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="showNew = !showNew">+ New feature</button>
        </div>

        <form v-if="showNew" @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-6 space-y-3 max-w-2xl">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <input v-model="form.key" placeholder="key (e.g. loyalty_points)" class="rounded-lg border-gray-300 text-sm w-full">
                    <div v-if="form.errors.key" class="text-rose-600 text-xs mt-1">{{ form.errors.key }}</div>
                </div>
                <input v-model="form.category" placeholder="category (billing/inventory/...)" class="rounded-lg border-gray-300 text-sm">
                <input v-model="form.label_bn" placeholder="বাংলা লেবেল" class="rounded-lg border-gray-300 text-sm">
                <input v-model="form.label_en" placeholder="English label" class="rounded-lg border-gray-300 text-sm">
                <input v-model.number="form.monthly_price" type="number" min="0" placeholder="Monthly price (৳)" class="rounded-lg border-gray-300 text-sm">
            </div>
            <textarea v-model="form.description" placeholder="Description (shown to admin when assigning)" class="rounded-lg border-gray-300 text-sm w-full" rows="2"></textarea>
            <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Save' }}
            </button>
        </form>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Key</th><th class="text-left px-4 py-3">Category</th>
                        <th class="text-left px-4 py-3">বাংলা</th><th class="text-left px-4 py-3">English</th>
                        <th class="text-left px-4 py-3">৳/month</th>
                        <th class="text-left px-4 py-3">Description</th>
                        <th class="text-left px-4 py-3">Recommended for</th>
                        <th class="text-left px-4 py-3">Active</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in features" :key="f.id" class="border-t">
                        <td class="px-4 py-3 font-mono text-xs">{{ f.key }}</td>
                        <td class="px-4 py-3 capitalize">{{ f.category }}</td>
                        <td class="px-4 py-3">{{ f.label_bn }}</td>
                        <td class="px-4 py-3">{{ f.label_en }}</td>
                        <td class="px-4 py-3">
                            <input :value="f.monthly_price" type="number" min="0" step="1"
                                class="w-20 rounded-lg border-gray-300 text-sm"
                                @change="savePrice(f, $event.target.value)">
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">{{ f.description }}</td>
                        <td class="px-4 py-3 text-xs max-w-xs">
                            <span v-if="recommendedFor[f.key]?.length" class="text-violet-700">{{ recommendedFor[f.key].join(', ') }}</span>
                            <span v-else class="text-gray-300">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="f.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                {{ f.is_active ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button class="text-xs font-semibold" :class="f.is_active ? 'text-rose-600' : 'text-emerald-600'" @click="toggleActive(f)">
                                {{ f.is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
