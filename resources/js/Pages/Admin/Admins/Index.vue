<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ admins: Array });
const page = usePage();
const myId = computed(() => page.props.auth?.admin?.id);

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', email: '', password: '', role: 'support' });

function openNew() {
    editing.value = null;
    form.reset();
    sheet.value = true;
}
function openEdit(admin) {
    editing.value = admin;
    form.name = admin.name;
    form.email = admin.email;
    form.password = '';
    form.role = admin.role;
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('admin.admins.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    } else {
        form.post(route('admin.admins.store'), { onSuccess: () => { sheet.value = false; } });
    }
}
function destroy(admin) {
    if (!confirm(`Delete admin account "${admin.name}"?`)) return;
    form.delete(route('admin.admins.destroy', admin.id));
}
</script>

<template>
    <Head title="Admin Accounts" />
    <AdminLayout active="admins">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Admin Accounts</h1>
            <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" @click="openNew">+ New admin</button>
        </div>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Email</th>
                        <th class="text-left px-4 py-3">Role</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in admins" :key="a.id" class="border-t">
                        <td class="px-4 py-3 font-medium">{{ a.name }} <span v-if="a.id === myId" class="text-xs text-gray-400">(you)</span></td>
                        <td class="px-4 py-3">{{ a.email }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold" :class="a.role === 'super_admin' ? 'bg-violet-100 text-violet-700' : 'bg-gray-100 text-gray-600'">{{ a.role }}</span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                            <button class="text-xs font-semibold text-gray-600" @click="openEdit(a)">Edit</button>
                            <button v-if="a.id !== myId" class="text-xs font-semibold text-rose-600" @click="destroy(a)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="sheet" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="sheet = false">
            <div class="bg-white rounded-xl p-6 w-full max-w-md">
                <div class="font-bold text-lg mb-4">{{ editing ? 'Edit admin' : 'New admin' }}</div>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs text-gray-500">Name</label>
                        <input v-model="form.name" class="w-full border rounded-lg px-3 py-2 text-sm mt-1">
                        <div v-if="form.errors.name" class="text-xs text-rose-600 mt-1">{{ form.errors.name }}</div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Email</label>
                        <input v-model="form.email" class="w-full border rounded-lg px-3 py-2 text-sm mt-1">
                        <div v-if="form.errors.email" class="text-xs text-rose-600 mt-1">{{ form.errors.email }}</div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Password {{ editing ? '(leave blank to keep current)' : '' }}</label>
                        <input v-model="form.password" type="password" class="w-full border rounded-lg px-3 py-2 text-sm mt-1">
                        <div v-if="form.errors.password" class="text-xs text-rose-600 mt-1">{{ form.errors.password }}</div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Role</label>
                        <select v-model="form.role" class="w-full border rounded-lg px-3 py-2 text-sm mt-1">
                            <option value="support">Support (view + impersonate, no destructive access)</option>
                            <option value="super_admin">Super admin (full access)</option>
                        </select>
                        <div v-if="form.errors.role" class="text-xs text-rose-600 mt-1">{{ form.errors.role }}</div>
                    </div>
                </div>
                <div class="flex gap-2 mt-5">
                    <button class="flex-1 px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" :disabled="form.processing" @click="save">{{ form.processing ? '...' : 'Save' }}</button>
                    <button class="px-4 py-2 rounded-lg border text-sm font-semibold text-gray-600" @click="sheet = false">Cancel</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
