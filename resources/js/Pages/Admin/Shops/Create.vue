<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ businessTypes: Array, features: Array });

// how many days each plan grants by default — used both for the initial
// form value and whenever the admin switches the Plan dropdown, so the
// expiry date never has to be hand-calculated
const PLAN_DAYS = { trial: 7, monthly: 30, yearly: 365 };
const addDays = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);

const form = useForm({
    name: '', name_en: '', phone: '', area: '', owner_name: '',
    business_type_id: '', sales_mode: 'both', plan: 'trial', lang: 'bn',
    subscription_start: new Date().toISOString().slice(0, 10),
    subscription_expiry: addDays(PLAN_DAYS.trial),
    owner_password: '1234',
    features: [],
    monthly_fee: null,
    staff_limit: null,
});

// sum of every ticked feature's own price — a starting point for what this
// shop's subscription is actually worth, not a locked number; the admin can
// still hand-edit monthly_fee below (real pricing involves negotiation)
const suggestedFee = computed(() =>
    props.features.filter((f) => form.features.includes(f.key)).reduce((sum, f) => sum + Number(f.monthly_price || 0), 0)
);
// only auto-fills monthly_fee while the admin hasn't typed their own number
// yet — once they touch it, ticking more features stops silently
// overwriting what they entered
const feeManuallyEdited = ref(false);
watch(suggestedFee, (v) => { if (!feeManuallyEdited.value) form.monthly_fee = v; });

// switching plan re-suggests the expiry date (admin can still hand-edit it
// afterward) — e.g. picking Trial always proposes "7 days from today"
watch(() => form.plan, (plan) => {
    form.subscription_expiry = addDays(PLAN_DAYS[plan] ?? PLAN_DAYS.trial);
});

// picking a business type pre-checks that type's recommended feature set
// (config/business_types.php, admin can still freely tick/untick before
// submitting) — saves manually reasoning through 15+ checkboxes for every
// new shop, and keeps a small mudir dokan from starting out with a cluttered
// purchase-ledger/stock-count toolkit it'll never touch.
watch(() => form.business_type_id, (id) => {
    const type = props.businessTypes.find((t) => t.id === id);
    form.features = type?.default_features ? [...type.default_features] : [];
});

const recommendedFeatureKeys = () => {
    const type = props.businessTypes.find((t) => t.id === form.business_type_id);
    return type?.default_features || [];
};

function submit() {
    form.post(route('admin.shops.store'));
}

const featuresByCategory = (list) => {
    const groups = {};
    for (const f of list) {
        (groups[f.category] ??= []).push(f);
    }
    return groups;
};
</script>

<template>
    <Head title="New Shop" />
    <AdminLayout active="shops">
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('admin.shops.index')" class="text-gray-500">←</Link>
            <h1 class="text-2xl font-bold">New Shop</h1>
        </div>

        <form @submit.prevent="submit" class="bg-white border rounded-xl p-6 max-w-2xl space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Shop name (Bangla)</label>
                    <input v-model="form.name" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.name" class="text-rose-600 text-xs mt-1">{{ form.errors.name }}</div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Shop name (English)</label>
                    <input v-model="form.name_en" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Phone (also login)</label>
                    <input v-model="form.phone" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.phone" class="text-rose-600 text-xs mt-1">{{ form.errors.phone }}</div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Area</label>
                    <input v-model="form.area" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-600">Owner name</label>
                <input v-model="form.owner_name" class="mt-1 w-full rounded-lg border-gray-300">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Business type</label>
                    <select v-model="form.business_type_id" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="">— select —</option>
                        <option v-for="t in businessTypes" :key="t.id" :value="t.id">{{ t.label_en }} ({{ t.label_bn }})</option>
                    </select>
                    <div v-if="form.errors.business_type_id" class="text-rose-600 text-xs mt-1">{{ form.errors.business_type_id }}</div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Sales mode</label>
                    <select v-model="form.sales_mode" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="both">Both (scan + manual)</option>
                        <option value="scan">Scan only</option>
                        <option value="manual">Manual only (no scanner)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Plan</label>
                    <select v-model="form.plan" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="trial">Trial (7 days free)</option>
                        <option value="monthly">Monthly (30 days)</option>
                        <option value="yearly">Yearly (365 days)</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Subscription start</label>
                    <input v-model="form.subscription_start" type="date" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Subscription expiry</label>
                    <input v-model="form.subscription_expiry" type="date" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.subscription_expiry" class="text-rose-600 text-xs mt-1">{{ form.errors.subscription_expiry }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">
                        Monthly fee (৳)
                        <span class="text-xs font-normal text-violet-600">— suggested from ticked features below: ৳{{ suggestedFee }}</span>
                    </label>
                    <input v-model.number="form.monthly_fee" type="number" min="0" class="mt-1 w-full rounded-lg border-gray-300" @input="feeManuallyEdited = true">
                    <div v-if="form.errors.monthly_fee" class="text-rose-600 text-xs mt-1">{{ form.errors.monthly_fee }}</div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">
                        Staff/cashier limit
                        <span class="text-xs font-normal text-gray-400">— blank = Business default (2), Ultimate typically 10</span>
                    </label>
                    <input v-model.number="form.staff_limit" type="number" min="0" placeholder="2" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.staff_limit" class="text-rose-600 text-xs mt-1">{{ form.errors.staff_limit }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Language</label>
                    <select v-model="form.lang" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="bn">বাংলা</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Owner login password</label>
                    <input v-model="form.owner_password" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.owner_password" class="text-rose-600 text-xs mt-1">{{ form.errors.owner_password }}</div>
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-600 block mb-2">
                    Features enabled for this shop
                    <span v-if="form.business_type_id" class="text-xs font-normal text-violet-600">— pre-checked from the business type, adjust freely below</span>
                </label>
                <div class="border rounded-lg p-4 space-y-3">
                    <div v-for="(list, cat) in featuresByCategory(features)" :key="cat">
                        <div class="text-xs font-bold uppercase text-gray-400 mb-1.5">{{ cat }}</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label v-for="f in list" :key="f.id" class="flex items-start gap-2 text-sm p-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                                <input type="checkbox" :value="f.key" v-model="form.features" class="mt-0.5">
                                <span>
                                    <span class="font-medium block">
                                        {{ f.label_en }} <span class="text-gray-400">({{ f.label_bn }})</span>
                                        <span v-if="Number(f.monthly_price) > 0" class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-700">৳{{ f.monthly_price }}/mo</span>
                                        <span v-if="recommendedFeatureKeys().includes(f.key)" class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-violet-100 text-violet-700">recommended</span>
                                    </span>
                                    <span v-if="f.description" class="text-xs text-gray-400">{{ f.description }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <button class="w-full py-3 rounded-lg [background:#7C3AED] text-white font-bold" :disabled="form.processing">
                {{ form.processing ? 'Creating...' : 'Create shop' }}
            </button>
        </form>
    </AdminLayout>
</template>
