<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    ambassador: Object, broughtShops: Array, currentShops: Array, commissions: Array, monthly: Array, allShops: Array,
});

const form = useForm({
    name: props.ambassador.name, employee_id: props.ambassador.employee_id, phone: props.ambassador.phone, email: props.ambassador.email,
    note: props.ambassador.note, one_time_bonus: Number(props.ambassador.one_time_bonus),
    recurring_percent: Number(props.ambassador.recurring_percent), status: props.ambassador.status,
});
function save() {
    form.put(route('admin.ambassadors.update', props.ambassador.id));
}

function markMonthPaid(month) {
    if (!confirm(`"${month}" মাসের সব unpaid কমিশন paid মার্ক করবেন?`)) return;
    router.post(route('admin.ambassadors.markPaid', props.ambassador.id), { month });
}
</script>

<template>
    <Head :title="ambassador.name" />
    <AdminLayout active="ambassadors">
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('admin.ambassadors.index')" class="text-gray-500">←</Link>
            <h1 class="text-2xl font-bold">{{ ambassador.name }}</h1>
            <a :href="route('admin.ambassadors.export', ambassador.id)" class="ml-auto px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 text-xs font-semibold">📊 Export (Excel)</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <form @submit.prevent="save" class="bg-white border rounded-xl p-5 space-y-3 lg:col-span-1">
                <h2 class="font-bold text-sm text-gray-600">Details & rates</h2>
                <div><label class="text-xs text-gray-500">Name</label><input v-model="form.name" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs text-gray-500">Employee ID</label><input v-model="form.employee_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs text-gray-500">Phone</label><input v-model="form.phone" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs text-gray-500">Email</label><input v-model="form.email" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></div>
                <div>
                    <label class="text-xs text-gray-500">One-time bonus (৳ per shop brought)</label>
                    <input v-model.number="form.one_time_bonus" type="number" min="0" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Recurring (% of every subscription payment)</label>
                    <input v-model.number="form.recurring_percent" type="number" min="0" max="100" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Status</label>
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </div>
                <textarea v-model="form.note" placeholder="Note" class="w-full rounded-lg border-gray-300 text-sm" rows="2"></textarea>
                <button class="w-full py-2 rounded-lg [background:#7C3AED] text-white text-sm font-bold" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </button>
            </form>

            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white border rounded-xl p-5">
                    <h2 class="font-bold text-sm text-gray-600 mb-3">Shops brought (all-time: {{ broughtShops.length }})</h2>
                    <div v-if="!broughtShops.length" class="text-sm text-gray-400">এখনো কোনো শপ এই ambassador-এর নামে bring করা হয়নি।</div>
                    <div v-for="s in broughtShops" :key="s.id" class="flex items-center justify-between py-1.5 border-t first:border-0 text-sm">
                        <span>{{ s.name }} <span class="text-gray-400 text-xs">({{ s.phone }})</span></span>
                        <span class="text-xs text-gray-400">{{ s.created_at?.slice(0, 10) }}</span>
                    </div>
                </div>

                <div class="bg-white border rounded-xl p-5">
                    <h2 class="font-bold text-sm text-gray-600 mb-3">Currently assigned (recurring %): {{ currentShops.length }}</h2>
                    <div v-if="!currentShops.length" class="text-sm text-gray-400">এই ambassador এখন কোনো শপের সাথে assigned না।</div>
                    <div v-for="s in currentShops" :key="s.id" class="py-1.5 border-t first:border-0 text-sm">{{ s.name }} <span class="text-gray-400 text-xs">({{ s.phone }})</span></div>
                </div>

                <div class="bg-white border rounded-xl overflow-x-auto">
                    <div class="px-5 py-3 font-bold text-sm text-gray-600 border-b">Monthly report</div>
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                            <tr>
                                <th class="text-left px-4 py-2">Month</th>
                                <th class="text-left px-4 py-2">One-time (count / ৳)</th>
                                <th class="text-left px-4 py-2">Recurring (৳)</th>
                                <th class="text-left px-4 py-2">Total</th>
                                <th class="text-left px-4 py-2">Unpaid</th>
                                <th class="text-right px-4 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in monthly" :key="m.month" class="border-t">
                                <td class="px-4 py-2 font-mono text-xs">{{ m.month }}</td>
                                <td class="px-4 py-2">{{ m.one_time_count }} / ৳{{ m.one_time_amount }}</td>
                                <td class="px-4 py-2">৳{{ m.recurring_amount }}</td>
                                <td class="px-4 py-2 font-semibold">৳{{ m.total }}</td>
                                <td class="px-4 py-2" :class="m.unpaid > 0 ? 'text-rose-600 font-semibold' : 'text-gray-400'">৳{{ m.unpaid }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button v-if="m.unpaid > 0" class="text-xs font-semibold text-emerald-600" @click="markMonthPaid(m.month)">Mark paid</button>
                                    <span v-else class="text-xs text-gray-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="!monthly.length"><td colspan="6" class="px-4 py-6 text-center text-gray-400">কোনো কমিশন রেকর্ড নেই।</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
