<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { matchPackage } from '@/composables/usePackageMatch';

const props = defineProps({ shop: Object, businessTypes: Array, features: Array, shopFeatureKeys: Array, branches: { type: Array, default: null } });

// only present when `shop` itself is a main shop (parent_shop_id null) --
// see ShopController::edit(). Billing fields mirror the main form above
// since a branch carries its own independent subscription, exactly like
// any other shop -- no separate billing mechanism needed.
const branchForm = useForm({
    name: '', area: '', phone: '', plan: 'monthly', monthly_fee: '',
    subscription_start: new Date().toISOString().slice(0, 10), subscription_expiry: '', is_warehouse: false,
});
function createBranch() {
    branchForm.post(route('admin.shops.branches.store', props.shop.id), {
        onSuccess: () => branchForm.reset(),
    });
}

const form = useForm({
    name: props.shop.name, name_en: props.shop.name_en, phone: props.shop.phone,
    area: props.shop.area, owner_name: props.shop.owner_name,
    support_contact_name: props.shop.support_contact_name, support_contact_phone: props.shop.support_contact_phone,
    business_type_id: props.shop.business_type_id, sales_mode: props.shop.sales_mode,
    plan: props.shop.plan, monthly_fee: props.shop.monthly_fee, staff_limit: props.shop.staff_limit, status: props.shop.status, lang: props.shop.lang,
    subscription_start: props.shop.subscription_start, subscription_expiry: props.shop.subscription_expiry,
    features: [...props.shopFeatureKeys],
});

// Shop::isActive() (what the shop-list's Active/Inactive pill and every
// login/subscription check actually reads) requires BOTH status='active'
// AND a future subscription_expiry -- selecting Active with an already-
// past expiry date saves fine but keeps showing Inactive with nothing
// explaining why, unless this exact combination is called out. The
// backend auto-extends it by a month in that case (ShopController::update),
// this is just making that visible instead of silent.
const expiryIsPast = computed(() => form.subscription_expiry && new Date(form.subscription_expiry) < new Date(new Date().toDateString()));
const willAutoExtend = computed(() => form.status === 'active' && expiryIsPast.value);

// reference only here (unlike Create.vue, this never auto-overwrites
// monthly_fee — an existing shop's price is already a deliberate, possibly
// negotiated number, not something a checkbox should silently change)
const suggestedFee = computed(() =>
    props.features.filter((f) => form.features.includes(f.key)).reduce((sum, f) => sum + Number(f.monthly_price || 0), 0)
);

// live "which of our 3 published packages does this ticked set look like"
// readout, per Khaled's own pricing flyer — so admin doesn't have to
// eyeball-compare the checklist against a screenshot every time
const featureLabel = (key) => props.features.find((f) => f.key === key)?.label_bn || key;
const packageMatch = computed(() => matchPackage(form.features));

function submit() {
    form.put(route('admin.shops.update', props.shop.id));
}

const featuresByCategory = (list) => {
    const groups = {};
    for (const f of list) {
        (groups[f.category] ??= []).push(f);
    }
    return groups;
};

// note only, never auto-applied here — this shop's grants are already
// deliberate, unlike Create.vue where pre-checking a fresh form is safe
const recommendedFeatureKeys = () => {
    const type = props.businessTypes.find((t) => t.id === form.business_type_id);
    return type?.default_features || [];
};
</script>

<template>
    <Head title="Edit Shop" />
    <AdminLayout active="shops">
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('admin.shops.index')" class="text-gray-500">←</Link>
            <h1 class="text-2xl font-bold">Edit {{ shop.name }}</h1>
        </div>

        <form @submit.prevent="submit" class="bg-white border rounded-xl p-6 max-w-2xl space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Shop name (Bangla)</label>
                    <input v-model="form.name" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.name" class="text-rose-600 text-xs mt-1">{{ form.errors.name }}</div>
                </div>
                <div><label class="text-sm font-medium text-gray-600">Shop name (English)</label><input v-model="form.name_en" class="mt-1 w-full rounded-lg border-gray-300"></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Phone</label>
                    <input v-model="form.phone" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.phone" class="text-rose-600 text-xs mt-1">{{ form.errors.phone }}</div>
                </div>
                <div><label class="text-sm font-medium text-gray-600">Area</label><input v-model="form.area" class="mt-1 w-full rounded-lg border-gray-300"></div>
            </div>
            <div><label class="text-sm font-medium text-gray-600">Owner name</label><input v-model="form.owner_name" class="mt-1 w-full rounded-lg border-gray-300"></div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Support contact name <span class="text-xs font-normal text-gray-400">— shown in this shop's own app footer, blank = hidden</span></label>
                    <input v-model="form.support_contact_name" placeholder="e.g. Zaylotix Support" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Support contact phone</label>
                    <input v-model="form.support_contact_phone" placeholder="e.g. 01XXXXXXXXX" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Business type</label>
                    <select v-model="form.business_type_id" class="mt-1 w-full rounded-lg border-gray-300">
                        <option v-for="t in businessTypes" :key="t.id" :value="t.id">{{ t.label_en }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Sales mode</label>
                    <select v-model="form.sales_mode" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="both">Both</option><option value="scan">Scan only</option><option value="manual">Manual only</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Plan</label>
                    <select v-model="form.plan" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="trial">Trial</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Status</label>
                    <select v-model="form.status" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Language</label>
                    <select v-model="form.lang" class="mt-1 w-full rounded-lg border-gray-300">
                        <option value="bn">বাংলা</option><option value="en">English</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="text-sm font-medium text-gray-600">Subscription start</label><input v-model="form.subscription_start" type="date" class="mt-1 w-full rounded-lg border-gray-300"></div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Subscription expiry</label>
                    <input v-model="form.subscription_expiry" type="date" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="willAutoExtend" class="text-xs text-amber-600 mt-1">⚠ এই তারিখ পার হয়ে গেছে — Active অবস্থায় সেভ করলে ১ মাস বাড়িয়ে দেওয়া হবে, নইলে দোকান তালিকায় Inactive-ই দেখাবে।</div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">
                        Monthly fee (৳)
                        <span class="text-xs font-normal text-violet-600">— ticked features currently add up to ৳{{ suggestedFee }}</span>
                    </label>
                    <input v-model.number="form.monthly_fee" type="number" min="0" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.monthly_fee" class="text-rose-600 text-xs mt-1">{{ form.errors.monthly_fee }}</div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">
                        Staff/cashier limit
                        <span class="text-xs font-normal text-gray-400">— blank = Business default (2)</span>
                    </label>
                    <input v-model.number="form.staff_limit" type="number" min="0" placeholder="2" class="mt-1 w-full rounded-lg border-gray-300">
                    <div v-if="form.errors.staff_limit" class="text-rose-600 text-xs mt-1">{{ form.errors.staff_limit }}</div>
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-gray-600 block mb-2">Features enabled for this shop</label>

                <!-- live "which published package does this match" readout —
                     compares the ticked set against Starter/Business/Ultimate's
                     core feature lists (see usePackageMatch.js), so admin
                     doesn't have to eyeball-compare against the pricing flyer -->
                <div class="rounded-lg p-3 mb-3 text-sm" :class="packageMatch.tier ? 'bg-violet-50 text-violet-800' : 'bg-amber-50 text-amber-800'">
                    <template v-if="packageMatch.tier">
                        <b>এই ফিচার সেট মিলছে: {{ packageMatch.label }} প্যাকেজের সাথে</b>
                        <div v-if="packageMatch.extras.length" class="text-xs mt-1">+ প্যাকেজের বাইরে অতিরিক্ত: {{ packageMatch.extras.map(featureLabel).join(', ') }}</div>
                        <div v-if="packageMatch.nextLabel" class="text-xs mt-1 text-violet-600">এর মধ্যে যেকোনো <u>একটা</u> ফিচার বাছাই করলেই {{ packageMatch.nextLabel }}-এ চলে যাবে: {{ packageMatch.missingForNext.map(featureLabel).join(', ') }}</div>
                    </template>
                    <template v-else>
                        <b>এখনো কোনো ফিচার বাছাই করা হয়নি</b>
                    </template>
                </div>

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
                {{ form.processing ? 'Saving...' : 'Save changes' }}
            </button>
        </form>

        <div v-if="branches" class="bg-white border rounded-xl p-6 max-w-2xl mt-6">
            <h2 class="text-lg font-bold mb-3">Branches</h2>
            <div v-for="b in branches" :key="b.id" class="flex items-center justify-between py-2 border-b last:border-0">
                <div>
                    <div class="font-medium">{{ b.is_warehouse ? '🏭' : '🏬' }} {{ b.name }}</div>
                    <div class="text-xs text-gray-400">{{ b.area }} • {{ b.status }} • created {{ b.created_at }}</div>
                </div>
                <Link :href="route('admin.shops.edit', b.id)" class="text-violet-600 text-sm">Edit →</Link>
            </div>
            <div v-if="!branches.length" class="text-sm text-gray-400 mb-3">No branches yet.</div>

            <form @submit.prevent="createBranch" class="mt-4 pt-4 border-t space-y-3">
                <div class="text-sm font-semibold text-gray-600">+ Add a branch</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><input v-model="branchForm.name" placeholder="Branch name" class="w-full rounded-lg border-gray-300 text-sm">
                        <div v-if="branchForm.errors.name" class="text-rose-600 text-xs mt-1">{{ branchForm.errors.name }}</div>
                    </div>
                    <input v-model="branchForm.area" placeholder="Area" class="w-full rounded-lg border-gray-300 text-sm">
                    <input v-model="branchForm.phone" placeholder="Phone" class="w-full rounded-lg border-gray-300 text-sm">
                    <select v-model="branchForm.plan" class="w-full rounded-lg border-gray-300 text-sm">
                        <option value="trial">Trial</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option>
                    </select>
                    <input v-model.number="branchForm.monthly_fee" type="number" min="0" placeholder="Monthly fee (৳)" class="w-full rounded-lg border-gray-300 text-sm">
                    <div></div>
                    <div><label class="text-xs text-gray-500">Subscription start</label><input v-model="branchForm.subscription_start" type="date" class="w-full rounded-lg border-gray-300 text-sm"></div>
                    <div><label class="text-xs text-gray-500">Subscription expiry</label><input v-model="branchForm.subscription_expiry" type="date" class="w-full rounded-lg border-gray-300 text-sm">
                        <div v-if="branchForm.errors.subscription_expiry" class="text-rose-600 text-xs mt-1">{{ branchForm.errors.subscription_expiry }}</div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" v-model="branchForm.is_warehouse" class="rounded"> 🏭 This is a warehouse (stock-only, not for POS sales)</label>
                </div>
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white text-sm font-bold" :disabled="branchForm.processing">
                    {{ branchForm.processing ? 'Creating...' : 'Create branch' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
