<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ shops: Array, totals: Object });

function mb(n) {
    return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 1 });
}
</script>

<template>
    <Head title="Shop Resource Usage" />
    <AdminLayout active="shopUsage">
        <h1 class="text-2xl font-bold mb-2">Shop Resource Usage</h1>
        <p class="text-sm text-gray-500 mb-6">
            Storage is measured exactly — database size is each shop's share of every table's real byte size (by row count), and file size is the real bytes of its uploaded product photos.
            <strong class="text-gray-700">CPU/RAM per shop can't be shown</strong> — this shared/Cloud hosting runs every shop through the same PHP workers with no per-tenant isolation to measure from, so there's nothing real to report there. Sales/product/user counts are shown instead, as the closest honest sign of how much a shop is actually using the system.
        </p>

        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Total database</div>
                <div class="text-lg font-extrabold mt-1">{{ mb(totals.db_mb) }} MB</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Total files</div>
                <div class="text-lg font-extrabold mt-1">{{ mb(totals.file_mb) }} MB</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Total storage</div>
                <div class="text-lg font-extrabold mt-1">{{ mb(totals.total_mb) }} MB</div>
            </div>
        </div>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Shop</th>
                        <th class="text-right px-4 py-3">Database</th>
                        <th class="text-right px-4 py-3">Files</th>
                        <th class="text-right px-4 py-3">Total</th>
                        <th class="text-left px-4 py-3">% of all storage</th>
                        <th class="text-right px-4 py-3">Sales (all-time)</th>
                        <th class="text-right px-4 py-3">Sales (30d)</th>
                        <th class="text-right px-4 py-3">Products</th>
                        <th class="text-right px-4 py-3">Users</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in shops" :key="s.id" class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ s.name }}</div>
                            <div class="text-xs text-gray-500">{{ s.phone }} · #{{ s.id }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">{{ mb(s.db_mb) }} MB</td>
                        <td class="px-4 py-3 text-right">{{ mb(s.file_mb) }} MB</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ mb(s.total_mb) }} MB</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-24 h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full bg-brand-purple" :style="{ width: Math.min(s.percent_of_total, 100) + '%' }" />
                                </div>
                                <span class="text-xs text-gray-500">{{ s.percent_of_total }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">{{ s.sales_count.toLocaleString('en-IN') }}</td>
                        <td class="px-4 py-3 text-right">{{ s.sales_last_30d.toLocaleString('en-IN') }}</td>
                        <td class="px-4 py-3 text-right">{{ s.product_count.toLocaleString('en-IN') }}</td>
                        <td class="px-4 py-3 text-right">{{ s.user_count.toLocaleString('en-IN') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
