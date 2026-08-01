<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ files: Array, connection: String });

const creating = ref(false);
function createBackup() {
    creating.value = true;
    router.post(route('admin.backups.store'), {}, { onFinish: () => (creating.value = false) });
}

function formatSize(bytes) {
    if (bytes > 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return Math.round(bytes / 1024) + ' KB';
}

function deleteFile(name) {
    if (!confirm(`Delete backup "${name}"? This cannot be undone.`)) return;
    router.delete(route('admin.backups.destroy', name));
}

// restore requires typing the literal word RESTORE — a checkbox or single
// click is not enough friction for an action that overwrites every shop's
// live data on the whole platform at once
const restoreTarget = ref(null);
const restoreForm = useForm({ confirm: '' });
function openRestore(name) {
    restoreTarget.value = name;
    restoreForm.reset();
}
function submitRestore() {
    restoreForm.post(route('admin.backups.restore', restoreTarget.value), {
        onSuccess: () => (restoreTarget.value = null),
    });
}
</script>

<template>
    <Head title="Backups" />
    <AdminLayout active="backups">
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Database Backups</h1>
            <p class="text-sm text-gray-500 mt-1">
                Whole-platform database dumps (every shop's data in one file) — automated nightly at 02:00, or trigger one now below.
                Admin-only: a backup/restore here always covers every tenant at once, never a single shop.
            </p>
        </div>

        <div class="bg-white border rounded-xl p-6 max-w-3xl">
            <div class="flex items-center justify-between mb-5">
                <div class="text-sm text-gray-500">Connection: <b class="text-gray-800">{{ connection }}</b>{{ connection !== 'mysql' ? ' — restore is only available on MySQL' : '' }}</div>
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm disabled:opacity-50" :disabled="creating" @click="createBackup">
                    {{ creating ? 'Backing up...' : '+ Backup now' }}
                </button>
            </div>

            <div v-if="!files.length" class="text-sm text-gray-400 py-8 text-center">No backups yet.</div>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2">File</th>
                        <th class="py-2">Size</th>
                        <th class="py-2">Created</th>
                        <th class="py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in files" :key="f.name" class="border-b last:border-0">
                        <td class="py-2.5 font-mono text-xs">{{ f.name }}</td>
                        <td class="py-2.5 text-gray-500">{{ formatSize(f.size) }}</td>
                        <td class="py-2.5 text-gray-500">{{ f.modified_at }}</td>
                        <td class="py-2.5 text-right whitespace-nowrap">
                            <a :href="route('admin.backups.download', f.name)" class="text-violet-700 font-medium mr-4">Download</a>
                            <button v-if="connection === 'mysql'" class="text-amber-700 font-medium mr-4" @click="openRestore(f.name)">Restore</button>
                            <button class="text-rose-600 font-medium" @click="deleteFile(f.name)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- restore confirmation -->
        <div v-if="restoreTarget" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="restoreTarget = null">
            <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
                <h2 class="font-bold text-lg text-rose-700 mb-2">⚠️ Restore the whole database?</h2>
                <p class="text-sm text-gray-600 mb-4">
                    This overwrites <b>every shop's</b> current data with the contents of <span class="font-mono text-xs">{{ restoreTarget }}</span>.
                    A fresh safety backup of the current state is taken automatically first, but this is still highly destructive.
                    Type <b>RESTORE</b> below to continue.
                </p>
                <input v-model="restoreForm.confirm" placeholder="RESTORE" class="border rounded-lg px-3 py-2 text-sm w-full mb-2">
                <div v-if="restoreForm.errors.confirm" class="text-rose-600 text-xs mb-2">{{ restoreForm.errors.confirm }}</div>
                <div class="flex gap-2 mt-3">
                    <button
                        class="px-4 py-2 rounded-lg bg-rose-600 text-white font-semibold text-sm disabled:opacity-50"
                        :disabled="restoreForm.processing || restoreForm.confirm !== 'RESTORE'"
                        @click="submitRestore"
                    >{{ restoreForm.processing ? 'Restoring...' : 'Restore now' }}</button>
                    <button class="px-4 py-2 rounded-lg border text-sm" @click="restoreTarget = null">Cancel</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
