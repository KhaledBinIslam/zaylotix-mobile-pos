<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ ambassadors: Array, shops: Array });

const showNew = ref(false);
const form = useForm({ name: '', employee_id: '', shop_id: '' });
function submit() {
    form.post(route('admin.ambassadors.store'), { onSuccess: () => { showNew.value = false; form.reset(); } });
}
</script>

<template>
    <Head title="Ambassadors" />
    <AdminLayout active="ambassadors">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">Ambassadors</h1>
                <p class="text-sm text-gray-500 mt-1">Sales-agent tracking — who brought each shop, their commission rates, and what's owed.</p>
            </div>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="showNew = !showNew">+ New ambassador</button>
        </div>

        <form v-if="showNew" @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-6 space-y-3 max-w-2xl">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <input v-model="form.name" placeholder="Name" class="rounded-lg border-gray-300 text-sm w-full">
                    <div v-if="form.errors.name" class="text-rose-600 text-xs mt-1">{{ form.errors.name }}</div>
                </div>
                <div>
                    <input v-model="form.employee_id" placeholder="Employee ID" class="rounded-lg border-gray-300 text-sm w-full">
                    <div v-if="form.errors.employee_id" class="text-rose-600 text-xs mt-1">{{ form.errors.employee_id }}</div>
                </div>
                <div>
                    <select v-model="form.shop_id" class="rounded-lg border-gray-300 text-sm w-full">
                        <option value="">— select shop —</option>
                        <option v-for="s in shops" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <div v-if="form.errors.shop_id" class="text-rose-600 text-xs mt-1">{{ form.errors.shop_id }}</div>
                </div>
            </div>
            <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Save' }}
            </button>
        </form>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Employee ID</th>
                        <th class="text-left px-4 py-3">Contact</th>
                        <th class="text-left px-4 py-3">One-time</th>
                        <th class="text-left px-4 py-3">Recurring %</th>
                        <th class="text-left px-4 py-3">Shops brought</th>
                        <th class="text-left px-4 py-3">This month</th>
                        <th class="text-left px-4 py-3">Currently assigned</th>
                        <th class="text-left px-4 py-3">Unpaid</th>
                        <th class="text-left px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in ambassadors" :key="a.id" class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <Link :href="route('admin.ambassadors.show', a.id)" class="font-medium text-gray-900 hover:text-violet-700 hover:underline">{{ a.name }}</Link>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ a.employee_id || '—' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ a.phone || a.email || '—' }}</td>
                        <td class="px-4 py-3">৳{{ a.one_time_bonus }}</td>
                        <td class="px-4 py-3">{{ a.recurring_percent }}%</td>
                        <td class="px-4 py-3">{{ a.brought_shops_count }}</td>
                        <td class="px-4 py-3">{{ a.brought_this_month }}</td>
                        <td class="px-4 py-3">{{ a.current_shops_count }}</td>
                        <td class="px-4 py-3 font-semibold" :class="a.unpaid_total > 0 ? 'text-rose-600' : 'text-gray-400'">৳{{ a.unpaid_total.toLocaleString('en-IN') }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="a.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-700'">{{ a.status }}</span>
                        </td>
                    </tr>
                    <tr v-if="!ambassadors.length"><td colspan="10" class="px-4 py-6 text-center text-gray-400">কোনো ambassador যোগ করা হয়নি।</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
