<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ providers: Array, configured: Object });

const activeTab = ref('sslcommerz');
const GATEWAY_FIELDS = {
    sslcommerz: [
        { key: 'store_id', label: 'Store ID' },
        { key: 'store_passwd', label: 'Store Password', secret: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
    bkash: [
        { key: 'app_key', label: 'App Key' },
        { key: 'app_secret', label: 'App Secret', secret: true },
        { key: 'username', label: 'Username' },
        { key: 'password', label: 'Password', secret: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
    nagad: [
        { key: 'merchant_id', label: 'Merchant ID' },
        { key: 'merchant_number', label: 'Merchant Number' },
        { key: 'public_key', label: "Nagad's Public Key (PEM)", secret: true, textarea: true },
        { key: 'private_key', label: 'Your Private Key (PEM)', secret: true, textarea: true },
        { key: 'sandbox', label: 'Sandbox mode', bool: true },
    ],
};
const form = useForm({});
function selectTab(provider) {
    activeTab.value = provider;
    const blank = {};
    GATEWAY_FIELDS[provider].forEach((f) => (blank[f.key] = f.bool ? true : ''));
    form.defaults(blank);
    form.reset();
}
selectTab('sslcommerz');

function save() {
    form.post(route('admin.platformGateway.store', activeTab.value), { preserveScroll: true });
}
function toggleActive(provider, isActive) {
    router.patch(route('admin.platformGateway.toggle', provider), { is_active: isActive }, { preserveScroll: true });
}
function disconnect(provider) {
    if (!confirm(`Disconnect Zaylotix's ${provider} account?`)) return;
    router.delete(route('admin.platformGateway.destroy', provider), { preserveScroll: true });
}
</script>

<template>
    <Head title="Platform Payment Gateway" />
    <AdminLayout active="platformGateway">
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Platform Payment Gateway</h1>
            <p class="text-sm text-gray-500 mt-1">Zaylotix's OWN bKash/Nagad/SSLCommerz merchant account — for shop owners paying their subscription (not a shop's own customer-payment account, that's configured per-shop instead). Once connected here and switched On, owners will see a "Renew subscription" option that charges their shop's current monthly fee automatically and extends their expiry the moment payment is confirmed.</p>
        </div>

        <div class="bg-white border rounded-xl p-6 max-w-2xl">
            <div class="flex gap-2 mb-4">
                <button
                    v-for="p in providers" :key="p"
                    class="px-4 py-2 rounded-lg text-sm font-semibold capitalize"
                    :class="activeTab === p ? '[background:#7C3AED] text-white' : 'bg-gray-100 text-gray-600'"
                    @click="selectTab(p)"
                >{{ p }}</button>
            </div>

            <div v-if="configured[activeTab]" class="bg-emerald-50 border border-emerald-200 rounded-lg p-4 mb-4">
                <div class="flex items-center justify-between">
                    <div>
                        <b class="text-emerald-700">✅ Connected</b>
                        <div class="text-xs text-gray-500 mt-1">{{ configured[activeTab].masked_summary }}</div>
                    </div>
                    <div class="flex gap-2">
                        <button class="px-3 py-1.5 rounded-lg text-xs font-semibold" :class="configured[activeTab].is_active ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500'" @click="toggleActive(activeTab, true)">On</button>
                        <button class="px-3 py-1.5 rounded-lg text-xs font-semibold" :class="!configured[activeTab].is_active ? 'bg-rose-600 text-white' : 'bg-gray-100 text-gray-500'" @click="toggleActive(activeTab, false)">Off</button>
                    </div>
                </div>
                <button class="mt-3 text-xs font-semibold text-rose-600" @click="disconnect(activeTab)">Disconnect</button>
            </div>
            <div v-else class="text-sm text-gray-400 mb-4">Not connected yet — owners won't see this option until it's connected and switched On.</div>

            <div v-for="f in GATEWAY_FIELDS[activeTab]" :key="f.key" class="mb-3">
                <label v-if="!f.bool" class="text-xs font-medium text-gray-500 block mb-1">{{ f.label }}</label>
                <label v-if="f.bool" class="flex items-center gap-2 text-sm cursor-pointer">
                    <input v-model="form[f.key]" type="checkbox" class="rounded">
                    {{ f.label }}
                </label>
                <textarea v-if="f.textarea" v-model="form[f.key]" rows="4" class="w-full rounded-lg border-gray-300 text-xs font-mono"></textarea>
                <input v-else-if="!f.bool" v-model="form[f.key]" :type="f.secret ? 'password' : 'text'" class="w-full rounded-lg border-gray-300 text-sm">
                <div v-if="form.errors[f.key]" class="text-rose-600 text-xs mt-1">{{ form.errors[f.key] }}</div>
            </div>
            <p class="text-xs text-gray-400 mb-4">Secrets are encrypted at rest and never sent back to the browser once saved.</p>
            <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold" :disabled="form.processing" @click="save">
                {{ form.processing ? 'Saving...' : 'Save' }}
            </button>
        </div>
    </AdminLayout>
</template>
