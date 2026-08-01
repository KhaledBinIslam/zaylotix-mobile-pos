<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({ payments: Array, shops: Array });

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// house rule: monthly-plan fees are due by the 5th of each month, so
// recording this month's payment defaults "next due" to the 5th of *next*
// month — the admin can still hand-edit it (e.g. for trial/yearly)
function fifthOfNextMonth() {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth() + 1, 5).toISOString().slice(0, 10);
}

const form = useForm({
    shop_id: '', plan: 'monthly', amount: '', month: new Date().toISOString().slice(0, 7),
    method: 'cash', paid_on: new Date().toISOString().slice(0, 10), next_due: fifthOfNextMonth(), note: '',
});

watch(() => form.plan, (plan) => {
    if (plan === 'monthly') form.next_due = fifthOfNextMonth();
});

function submit() {
    form.post(route('admin.subscriptions.store'), { onSuccess: () => form.reset('amount', 'note') });
}
</script>

<template>
    <Head title="Subscriptions" />
    <AdminLayout active="subscriptions">
        <h1 class="text-2xl font-bold mb-6">Subscription Payments</h1>

        <form @submit.prevent="submit" class="bg-white border rounded-xl p-5 mb-8 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
            <div class="col-span-2">
                <label class="text-xs font-medium text-gray-600">Shop</label>
                <select v-model="form.shop_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— select —</option>
                    <option v-for="s in shops" :key="s.id" :value="s.id">{{ s.name }} ({{ s.phone }})</option>
                </select>
                <div v-if="form.errors.shop_id" class="text-rose-600 text-xs mt-1">{{ form.errors.shop_id }}</div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Plan</label>
                <select v-model="form.plan" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    <option value="trial">Trial</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Amount</label>
                <input v-model="form.amount" type="number" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                <div v-if="form.errors.amount" class="text-rose-600 text-xs mt-1">{{ form.errors.amount }}</div>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Month (YYYY-MM)</label>
                <input v-model="form.month" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Method</label>
                <input v-model="form.method" placeholder="bkash / cash" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Paid on</label>
                <input v-model="form.paid_on" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Next due (auto-deactivates after this date if unpaid)</label>
                <input v-model="form.next_due" type="date" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </div>
            <div class="col-span-2">
                <label class="text-xs font-medium text-gray-600">Note</label>
                <input v-model="form.note" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </div>
            <button class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold h-fit" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : 'Record payment' }}
            </button>
        </form>

        <div class="bg-white border rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr><th class="text-left px-4 py-3">Shop</th><th class="text-left px-4 py-3">Month</th><th class="text-left px-4 py-3">Plan</th><th class="text-left px-4 py-3">Amount</th><th class="text-left px-4 py-3">Method</th><th class="text-left px-4 py-3">Paid on</th><th class="text-left px-4 py-3">Next due</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in payments" :key="p.id" class="border-t">
                        <td class="px-4 py-3">{{ p.shop?.name }}</td>
                        <td class="px-4 py-3">{{ p.month }}</td>
                        <td class="px-4 py-3 capitalize">{{ p.plan }}</td>
                        <td class="px-4 py-3 font-semibold">{{ money(p.amount) }}</td>
                        <td class="px-4 py-3">{{ p.method }}</td>
                        <td class="px-4 py-3">{{ p.paid_on }}</td>
                        <td class="px-4 py-3">{{ p.next_due || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
