<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    db: Object, recentErrors: Array, incidents: { type: Array, default: () => [] }, disk: Object, appUsage: Object,
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
                <div class="text-xs text-gray-500">Disk free <span class="text-gray-400">(whole shared host)</span></div>
                <div class="text-lg font-extrabold mt-1 text-gray-900">
                    {{ disk.free_gb !== null ? disk.free_gb + ' GB' : '—' }}
                </div>
                <div class="text-xs text-gray-400" v-if="disk.used_percent !== null">{{ disk.used_percent }}% used of {{ disk.total_gb }} GB — every Hostinger customer on this server combined, not just this app</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500 flex items-center justify-between">
                    <span>This app's own storage</span>
                    <Link :href="route('admin.shops.usage')" class="text-brand-purple-700 font-semibold">per-shop ›</Link>
                </div>
                <div class="text-lg font-extrabold mt-1 text-gray-900">{{ appUsage.total_mb.toLocaleString('en-IN') }} MB</div>
                <div class="text-xs text-gray-400">{{ appUsage.db_mb.toLocaleString('en-IN') }} MB database · {{ appUsage.file_mb.toLocaleString('en-IN') }} MB files</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">App environment</div>
                <div class="text-lg font-extrabold mt-1">{{ app_env }}</div>
                <div class="text-xs" :class="app_debug ? 'text-rose-500' : 'text-gray-400'">debug: {{ app_debug ? 'ON ⚠' : 'off' }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Versions</div>
                <div class="text-sm font-semibold mt-1">PHP {{ php_version }}</div>
                <div class="text-xs text-gray-400">Laravel {{ laravel_version }} · queue: {{ queue_connection }}</div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-4">
            <div class="font-bold mb-1">📣 What users ran into (reference codes, phone errors, forced logouts)</div>
            <div class="text-xs text-gray-400 mb-3">A "server" row has the reference code printed on the user's screen — search it in storage/logs/laravel.log for the full trace.</div>
            <div v-if="!incidents.length" class="text-sm text-gray-400">Nothing reported lately. 🎉</div>
            <div v-for="(e, i) in incidents" :key="'i' + i" class="py-2 border-b last:border-0">
                <div class="flex items-center gap-2 text-xs mb-1">
                    <span class="px-1.5 py-0.5 rounded font-semibold" :class="e.kind === 'server' ? 'bg-rose-50 text-rose-700' : (e.kind === 'phone' ? 'bg-amber-50 text-amber-700' : 'bg-sky-50 text-sky-700')">{{ e.kind }}</span>
                    <span v-if="e.ref" class="font-mono font-bold text-gray-800">{{ e.ref }}</span>
                    <span class="text-gray-400">{{ e.time }}</span>
                </div>
                <div class="text-sm font-mono text-gray-700 break-all">{{ e.message }}</div>
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
