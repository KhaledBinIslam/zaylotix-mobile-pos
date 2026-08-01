<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ logs: Object });
</script>

<template>
    <Head title="Activity Log" />
    <AdminLayout active="activityLog">
        <h1 class="text-2xl font-bold mb-6">Admin Activity Log</h1>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Admin</th>
                        <th class="text-left px-4 py-3">Action</th>
                        <th class="text-left px-4 py-3">Description</th>
                        <th class="text-left px-4 py-3">When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id" class="border-t">
                        <td class="px-4 py-3">{{ log.admin?.name || 'System' }}</td>
                        <td class="px-4 py-3"><span class="px-2 py-1 rounded-full text-xs font-semibold bg-violet-50 text-violet-700">{{ log.action }}</span></td>
                        <td class="px-4 py-3">{{ log.description }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ log.created_at }}</td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="4" class="px-4 py-8 text-center text-gray-400">No activity yet.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex gap-2 flex-wrap">
            <template v-for="(link, i) in logs.links" :key="i">
                <Link
                    v-if="link.url" :href="link.url"
                    class="px-3 py-1.5 rounded-lg text-sm border"
                    :class="link.active ? 'bg-violet-600 text-white border-violet-600' : 'text-gray-600 border-gray-200'"
                    v-html="link.label"
                />
                <span v-else class="px-3 py-1.5 rounded-lg text-sm border text-gray-300 border-gray-100" v-html="link.label" />
            </template>
        </div>
    </AdminLayout>
</template>
