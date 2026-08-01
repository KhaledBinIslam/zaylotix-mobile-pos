<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ shops: Array });

const totalMrr = computed(() => props.shops.filter((s) => s.is_active).reduce((sum, s) => sum + Number(s.monthly_fee || 0), 0));

function toggle(shop) {
    const action = shop.status === 'active' ? 'deactivate' : 'activate';
    if (!confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} "${shop.name}"? ${action === 'deactivate' ? 'The owner will be logged out and unable to log back in.' : ''}`)) return;
    router.post(route('admin.shops.toggleStatus', shop.id));
}

function impersonate(shop) {
    if (!confirm(`Log in as "${shop.name}"'s owner? This is logged in the activity log.`)) return;
    router.post(route('admin.impersonate.start', shop.id));
}
</script>

<template>
    <Head title="Shops" />
    <AdminLayout active="shops">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">Shops</h1>
                <p class="text-sm text-gray-500 mt-1">Active MRR: <span class="font-semibold text-emerald-700">৳{{ totalMrr.toLocaleString('en-IN') }}</span>/month across {{ shops.filter(s => s.is_active).length }} active shops</p>
            </div>
            <Link :href="route('admin.shops.create')" class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm">+ New shop</Link>
        </div>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Shop</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Plan</th>
                        <th class="text-left px-4 py-3">Fee (৳/mo)</th>
                        <th class="text-left px-4 py-3">Sales mode</th>
                        <th class="text-left px-4 py-3">Expiry</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in shops" :key="s.id" class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <Link :href="route('admin.shops.show', s.id)" class="font-medium text-gray-900 hover:text-violet-700 hover:underline" title="Export/delete data for this shop">{{ s.name }}</Link>
                            <div class="text-xs text-gray-500">{{ s.phone }}</div>
                        </td>
                        <td class="px-4 py-3">{{ s.business_type }}</td>
                        <td class="px-4 py-3 capitalize">{{ s.plan }}</td>
                        <td class="px-4 py-3">{{ s.monthly_fee ? '৳' + s.monthly_fee.toLocaleString('en-IN') : '—' }}</td>
                        <td class="px-4 py-3 capitalize">{{ s.sales_mode }}</td>
                        <td class="px-4 py-3">
                            {{ s.subscription_expiry }}
                            <span v-if="s.days_left !== null" class="block text-xs" :class="s.days_left < 3 ? 'text-rose-600' : 'text-gray-400'">{{ s.days_left }} days left</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="s.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                {{ s.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                            <button class="inline-block text-xs font-semibold px-2.5 py-1 rounded-md bg-violet-50 text-violet-700" title="Log in as this shop's owner for support" @click="impersonate(s)">🕵️ View as</button>
                            <Link :href="route('admin.shops.show', s.id)" class="inline-block text-xs font-semibold px-2.5 py-1 rounded-md bg-sky-50 text-sky-700" title="Export data (Excel/SQL) or delete records">📊 Data</Link>
                            <Link :href="route('admin.shops.edit', s.id)" class="text-xs font-semibold text-gray-600">Edit</Link>
                            <button class="text-xs font-semibold" :class="s.status === 'active' ? 'text-rose-600' : 'text-emerald-600'" @click="toggle(s)">
                                {{ s.status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
