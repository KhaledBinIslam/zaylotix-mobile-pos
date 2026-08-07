<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ stats: Object, mostActiveShops: Array, featureAdoption: Array, signupTrend: Array, shopUsage: Array });

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
</script>

<template>
    <Head title="Analytics" />
    <AdminLayout active="analytics">
        <h1 class="text-2xl font-bold mb-6">Platform Analytics</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Sales today</div>
                <div class="text-2xl font-extrabold mt-1 text-emerald-600">{{ money(stats.salesToday) }}</div>
                <div class="text-xs text-gray-400">{{ stats.billsToday }} bills</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Sales this week</div>
                <div class="text-2xl font-extrabold mt-1">{{ money(stats.salesWeek) }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Sales this month</div>
                <div class="text-2xl font-extrabold mt-1">{{ money(stats.salesMonth) }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Total shops</div>
                <div class="text-2xl font-extrabold mt-1 [color:#7C3AED]">{{ stats.totalShops }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Shops active today (made ≥1 sale)</div>
                <div class="text-2xl font-extrabold mt-1">{{ stats.activeShopsToday }} <span class="text-sm font-normal text-gray-400">/ {{ stats.totalShops }}</span></div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Shops active this week</div>
                <div class="text-2xl font-extrabold mt-1">{{ stats.activeShopsWeek }} <span class="text-sm font-normal text-gray-400">/ {{ stats.totalShops }}</span></div>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-6 mb-6">
            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">🏆 Most active shops (last 30 days)</div>
                <div v-if="!mostActiveShops.length" class="text-sm text-gray-400">No sales yet.</div>
                <div v-for="s in mostActiveShops" :key="s.shop_id" class="flex justify-between items-center py-2 border-b last:border-0">
                    <div class="font-medium text-sm">{{ s.shop_name }}</div>
                    <div class="text-right">
                        <div class="text-sm font-semibold">{{ money(s.total_revenue) }}</div>
                        <div class="text-xs text-gray-400">{{ s.sale_count }} sales</div>
                    </div>
                </div>
            </div>

            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">📅 Signups per week</div>
                <div v-for="w in signupTrend" :key="w.week_of" class="flex justify-between items-center py-2 border-b last:border-0">
                    <div class="text-sm text-gray-600">Week of {{ w.week_of }}</div>
                    <div class="text-sm font-semibold">{{ w.count }}</div>
                </div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-6">
            <div class="font-bold mb-1">📦 Per-shop usage (hosting load)</div>
            <div class="text-xs text-gray-400 mb-3">Shared-schema app — no per-tenant disk number exists, so row counts across the biggest tables are the closest real proxy for load. Storage is actual uploaded file size (logo + product photos).</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="text-left px-3 py-2">Shop</th>
                            <th class="text-right px-3 py-2">Products</th>
                            <th class="text-right px-3 py-2">Sales</th>
                            <th class="text-right px-3 py-2">Sale lines</th>
                            <th class="text-right px-3 py-2">Customers</th>
                            <th class="text-right px-3 py-2">Total rows</th>
                            <th class="text-right px-3 py-2">Storage (MB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shopUsage" :key="s.id" class="border-t">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ s.name }}</div>
                                <div class="text-xs text-gray-400">{{ s.phone }}</div>
                            </td>
                            <td class="text-right px-3 py-2">{{ s.product_count }}</td>
                            <td class="text-right px-3 py-2">{{ s.sale_count }}</td>
                            <td class="text-right px-3 py-2">{{ s.sale_item_count }}</td>
                            <td class="text-right px-3 py-2">{{ s.customer_count }}</td>
                            <td class="text-right px-3 py-2 font-semibold">{{ s.row_count }}</td>
                            <td class="text-right px-3 py-2">{{ s.storage_mb }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5">
            <div class="font-bold mb-3">🧩 Feature adoption</div>
            <div v-for="f in featureAdoption" :key="f.key" class="py-2 border-b last:border-0">
                <div class="flex justify-between items-center text-sm mb-1">
                    <span>{{ f.label }} <span class="text-xs text-gray-400">({{ f.category }})</span></span>
                    <span class="font-semibold">{{ f.shop_count }} shops ({{ f.percent }}%)</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full [background:#7C3AED]" :style="{ width: f.percent + '%' }"></div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
