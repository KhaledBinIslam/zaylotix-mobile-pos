<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    shop: Object, productCount: Number, customerCount: Number, totalDue: Number,
    recentSales: Array, products: Array, customers: Array,
});

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function deleteProduct(p) {
    if (!confirm(`পণ্য "${p.name}" মুছে ফেলতে চান? এটি আর স্টক/POS-এ দেখাবে না, কিন্তু পুরনো বিক্রির হিসাব ঠিক থাকবে।`)) return;
    router.delete(route('admin.shops.products.destroy', [props.shop.id, p.id]), { preserveScroll: true });
}

function deleteCustomer(c) {
    if (!confirm(`কাস্টমার "${c.name}" মুছে ফেলতে চান?`)) return;
    router.delete(route('admin.shops.customers.destroy', [props.shop.id, c.id]), { preserveScroll: true });
}

function deleteSale(s) {
    if (!confirm(`মেমো "${s.invoice_no}" মুছে ফেলতে চান? স্টক ফেরত যাবে এবং দোকানের ব্যালেন্স/কাস্টমারের বাকি ঠিক করে দেওয়া হবে। এটা ফেরানো যাবে না।`)) return;
    router.delete(route('admin.shops.sales.destroy', [props.shop.id, s.id]), { preserveScroll: true });
}

// full shop wipe — deliberately more friction than a plain confirm() dialog,
// since this is irreversible and destroys every row the shop ever created
const dangerOpen = ref(false);
const deleteForm = useForm({ confirm_name: '' });
function destroyShop() {
    deleteForm.delete(route('admin.shops.destroy', props.shop.id));
}

// free-text notification to just this shop's owner — shows up as a bell
// notification on their end (see AppLayout.vue's notification dropdown)
const notifyForm = useForm({ shop_id: props.shop.id, message: '' });
function sendNotification() {
    notifyForm.post(route('admin.notifications.store'), {
        preserveScroll: true,
        onSuccess: () => notifyForm.reset('message'),
    });
}
</script>

<template>
    <Head :title="shop.name" />
    <AdminLayout active="shops">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <Link :href="route('admin.shops.index')" class="text-gray-500">←</Link>
                <h1 class="text-2xl font-bold">{{ shop.name }} <span class="text-sm font-normal text-gray-400">shop audit &amp; support tools</span></h1>
            </div>
            <div class="flex gap-2">
                <Link :href="route('admin.shops.edit', shop.id)" class="px-4 py-2 rounded-lg border text-sm font-semibold">✏️ Edit</Link>
                <a :href="route('admin.shops.export', shop.id)" class="px-4 py-2 rounded-lg [background:#7C3AED] text-white text-sm font-semibold">
                    📤 Export Excel
                </a>
                <a :href="route('admin.shops.exportSql', shop.id)" class="px-4 py-2 rounded-lg bg-gray-800 text-white text-sm font-semibold" title="Portable SQL dump — importable into a fresh install for self-hosting or migration">
                    🗄️ Export SQL
                </a>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Products</div><div class="text-xl font-bold mt-1">{{ productCount }}</div></div>
            <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Customers</div><div class="text-xl font-bold mt-1">{{ customerCount }}</div></div>
            <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Total due</div><div class="text-xl font-bold mt-1 text-rose-600">{{ money(totalDue) }}</div></div>
        </div>

        <div v-if="shop.features?.length" class="bg-white border rounded-xl p-5 mb-6">
            <div class="font-bold mb-3">Enabled features</div>
            <div class="flex flex-wrap gap-2">
                <span v-for="f in shop.features" :key="f.id" class="px-2.5 py-1 rounded-full text-xs font-semibold bg-violet-50 text-violet-700">{{ f.label_en }}</span>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-6">
            <div class="font-bold mb-3">Recent sales <span class="text-xs font-normal text-gray-400">— delete reverses stock/balance/due automatically</span></div>
            <div v-for="s in recentSales" :key="s.id" class="flex justify-between items-center py-2 border-b last:border-0 text-sm">
                <div>
                    <div class="font-medium">{{ s.invoice_no }}</div>
                    <div class="text-xs text-gray-500">{{ s.date }} • {{ s.customer?.name || 'Walk-in' }}</div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="font-semibold">{{ money(s.total) }}</div>
                    <button class="text-rose-600 hover:text-rose-800 text-xs font-semibold" @click="deleteSale(s)">🗑️ Delete</button>
                </div>
            </div>
            <div v-if="!recentSales.length" class="text-sm text-gray-400">No sales yet.</div>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">Products ({{ products.length }})</div>
                <div class="max-h-80 overflow-y-auto">
                    <div v-for="p in products" :key="p.id" class="flex justify-between items-center py-1.5 border-b last:border-0 text-sm">
                        <div class="truncate pr-2">{{ p.name }} <span class="text-xs text-gray-400">— stock {{ p.stock }}</span></div>
                        <button class="text-rose-600 hover:text-rose-800 text-xs font-semibold shrink-0" @click="deleteProduct(p)">🗑️</button>
                    </div>
                    <div v-if="!products.length" class="text-sm text-gray-400">No products yet.</div>
                </div>
            </div>
            <div class="bg-white border rounded-xl p-5">
                <div class="font-bold mb-3">Customers ({{ customers.length }})</div>
                <div class="max-h-80 overflow-y-auto">
                    <div v-for="c in customers" :key="c.id" class="flex justify-between items-center py-1.5 border-b last:border-0 text-sm">
                        <div class="truncate pr-2">{{ c.name }} <span class="text-xs text-gray-400">— {{ c.phone || 'no phone' }}</span></div>
                        <button class="text-rose-600 hover:text-rose-800 text-xs font-semibold shrink-0" @click="deleteCustomer(c)">🗑️</button>
                    </div>
                    <div v-if="!customers.length" class="text-sm text-gray-400">No customers yet.</div>
                </div>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-5 mb-6">
            <div class="font-bold mb-1">📢 Send notification</div>
            <p class="text-sm text-gray-500 mb-3">Sends a message straight to this shop owner's notification bell (see the 🔔 icon in their app) — for anything the automatic payment notifications don't cover.</p>
            <form @submit.prevent="sendNotification" class="flex items-end gap-2">
                <div class="flex-1">
                    <textarea v-model="notifyForm.message" rows="2" placeholder="e.g. New features added — check the update!" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                    <div v-if="notifyForm.errors.message" class="text-rose-600 text-xs mt-1">{{ notifyForm.errors.message }}</div>
                </div>
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white text-sm font-semibold h-fit" :disabled="notifyForm.processing">
                    {{ notifyForm.processing ? 'Sending...' : 'Send' }}
                </button>
            </form>
        </div>

        <div class="bg-rose-50 border border-rose-200 rounded-xl p-5">
            <div class="font-bold text-rose-700 mb-1">⚠️ Danger zone</div>
            <p class="text-sm text-rose-700 mb-3">Permanently deletes this shop and every product, sale, customer, and record it has — irreversible. Only use this when a shop is truly being shut down, not for cleaning up a mistake (use the delete buttons above for that).</p>

            <button v-if="!dangerOpen" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-semibold" @click="dangerOpen = true">
                Delete this shop permanently
            </button>
            <form v-else @submit.prevent="destroyShop" class="flex items-end gap-2">
                <div>
                    <label class="text-xs font-medium text-rose-700 block mb-1">Type the shop's exact name to confirm: <b>{{ shop.name }}</b></label>
                    <input v-model="deleteForm.confirm_name" class="rounded-lg border-rose-300 text-sm" style="min-width:260px">
                    <div v-if="deleteForm.errors.confirm_name" class="text-rose-600 text-xs mt-1">{{ deleteForm.errors.confirm_name }}</div>
                </div>
                <button class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-semibold" :disabled="deleteForm.processing">
                    {{ deleteForm.processing ? 'Deleting...' : 'Confirm delete' }}
                </button>
                <button type="button" class="px-4 py-2 rounded-lg border text-sm" @click="dangerOpen = false; deleteForm.reset()">Cancel</button>
            </form>
        </div>
    </AdminLayout>
</template>
