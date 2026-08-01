<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    db: Object, recentErrors: Array, disk: Object,
    php_version: String, laravel_version: String, queue_connection: String, app_env: String, app_debug: Boolean,
});
</script>

<template>
    <Head title="System Health" />
    <AdminLayout active="systemHealth">
        <h1 class="text-2xl font-bold mb-6">System Health</h1>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Database</div>
                <div class="text-lg font-extrabold mt-1" :class="db.ok ? 'text-emerald-600' : 'text-rose-600'">{{ db.ok ? '✅ Connected' : '❌ Down' }}</div>
                <div v-if="db.error" class="text-xs text-rose-500 mt-1">{{ db.error }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Disk free</div>
                <div class="text-lg font-extrabold mt-1" :class="(disk.used_percent || 0) > 90 ? 'text-rose-600' : 'text-gray-900'">
                    {{ disk.free_gb !== null ? disk.free_gb + ' GB' : '—' }}
                </div>
                <div class="text-xs text-gray-400" v-if="disk.used_percent !== null">{{ disk.used_percent }}% used of {{ disk.total_gb }} GB</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">App environment</div>
                <div class="text-lg font-extrabold mt-1">{{ app_env }}</div>
                <div class="text-xs" :class="app_debug ? 'text-rose-500' : 'text-gray-400'">debug: {{ app_debug ? 'ON ⚠️' : 'off' }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Versions</div>
                <div class="text-sm font-semibold mt-1">PHP {{ php_version }}</div>
                <div class="text-xs text-gray-400">Laravel {{ laravel_version }} · queue: {{ queue_connection }}</div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5">
            <div class="font-bold mb-3">🚨 Recent errors (from storage/logs/laravel.log)</div>
            <div v-if="!recentErrors.length" class="text-sm text-gray-400">No recent errors logged. 🎉</div>
            <div v-for="(e, i) in recentErrors" :key="i" class="py-2 border-b last:border-0">
                <div class="flex items-center gap-2 text-xs mb-1">
                    <span class="px-1.5 py-0.5 rounded font-semibold bg-rose-50 text-rose-700">{{ e.level }}</span>
                    <span class="text-gray-400">{{ e.time }}</span>
                </div>
                <div class="text-sm font-mono text-gray-700 break-all">{{ e.message }}</div>
            </div>
        </div>
    </AdminLayout>
</template>
