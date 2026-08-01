<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ stats: Object, unpaidShops: Array, paidShops: Array, expiringSoon: Array });

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// shop_id intentionally omitted — the backend treats that as "every active shop"
const broadcastForm = useForm({ message: '' });
function sendBroadcast() {
    broadcastForm.post(route('admin.notifications.store'), {
        preserveScroll: true,
        onSuccess: () => broadcastForm.reset('message'),
    });
}
</script>

<template>
    <Head title="Admin Dashboard" />
    <AdminLayout active="dashboard">
        <h1 class="text-2xl font-bold mb-6">Dashboard</h1>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Total shops</div>
                <div class="text-2xl font-extrabold mt-1">{{ stats.totalShops }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Active</div>
                <div class="text-2xl font-extrabold mt-1 text-emerald-600">{{ stats.activeShops }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Inactive</div>
                <div class="text-2xl font-extrabold mt-1 text-rose-600">{{ stats.inactiveShops }}</div>
            </div>
            <div class="bg-white border rounded-xl p-4 shadow-sm">
                <div class="text-xs text-gray-500">Collected this month</div>
                <div class="text-2xl font-extrabold mt-1 [color:#7C3AED]">{{ money(stats.collectedThisMonth) }}</div>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-6 mb-6">
            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">⚠️ Not paid this month</div>
                <div v-if="!unpaidShops.length" class="text-sm text-gray-400">Everyone active has paid.</div>
                <div v-for="s in unpaidShops" :key="s.id" class="flex justify-between items-center py-2 border-b last:border-0">
                    <div>
                        <div class="font-medium text-sm">{{ s.name }}</div>
                        <div class="text-xs text-gray-500">{{ s.phone }}</div>
                    </div>
                    <Link :href="route('admin.subscriptions.index')" class="text-xs font-semibold [color:#7C3AED]">Record payment</Link>
                </div>
            </div>

            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">✅ Paid this month</div>
                <div v-if="!paidShops.length" class="text-sm text-gray-400">Nobody's paid yet this month.</div>
                <div v-for="s in paidShops" :key="s.id" class="flex justify-between items-center py-2 border-b last:border-0">
                    <div>
                        <div class="font-medium text-sm">{{ s.name }}</div>
                        <div class="text-xs text-gray-500">{{ s.phone }}</div>
                    </div>
                    <div class="text-xs font-semibold text-emerald-600">{{ money(s.paid_amount) }}</div>
                </div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-6">
            <div class="font-bold mb-3">⏰ Expiring within 7 days</div>
            <div v-if="!expiringSoon.length" class="text-sm text-gray-400">Nothing expiring soon.</div>
            <div v-for="s in expiringSoon" :key="s.id" class="flex justify-between items-center py-2 border-b last:border-0">
                <div>
                    <div class="font-medium text-sm">{{ s.name }}</div>
                    <div class="text-xs text-gray-500">{{ s.phone }}</div>
                </div>
                <div class="text-xs font-semibold text-rose-600">{{ s.subscription_expiry }}</div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5">
            <div class="font-bold mb-1">📢 Broadcast to every active shop</div>
            <p class="text-sm text-gray-500 mb-3">Sends to every active shop owner's notification bell at once — for announcements (new features, maintenance, etc). To message just one shop, use the "Send notification" box on that shop's page instead.</p>
            <form @submit.prevent="sendBroadcast" class="flex items-end gap-2">
                <div class="flex-1">
                    <textarea v-model="broadcastForm.message" rows="2" placeholder="e.g. Zaylotix POS will be under maintenance tonight 11pm-12am." class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                    <div v-if="broadcastForm.errors.message" class="text-rose-600 text-xs mt-1">{{ broadcastForm.errors.message }}</div>
                </div>
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white text-sm font-semibold h-fit" :disabled="broadcastForm.processing">
                    {{ broadcastForm.processing ? 'Sending...' : `Send to ${stats.activeShops} shop(s)` }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
