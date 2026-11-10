<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useToast } from '@/composables/useToast';

/**
 * "product wise serial wise size color wise group wise" price update —
 * select any mix of whole products and individual size/colour variants,
 * type each one's own new cost/sell price, save together. See
 * PriceUpdateController's own docblock for the barcode-rebuild-on-cost-
 * change behaviour.
 */
const props = defineProps({ products: Array, categories: Array, filters: Object });
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const categoryId = ref(props.filters.category_id || '');
const search = ref(props.filters.q || '');
function applyFilters() {
    router.get(route('app.prices.index'), { category_id: categoryId.value || undefined, q: search.value || undefined }, { preserveState: true, replace: true });
}

const expanded = reactive({});
function toggleExpand(id) {
    expanded[id] = !expanded[id];
}

// id -> { cost, price } for every CHECKED product/variant row
const productEdits = reactive({});
const variantEdits = reactive({});

function toggleProduct(p) {
    if (productEdits[p.id]) delete productEdits[p.id];
    else productEdits[p.id] = { cost: p.cost, price: p.price };
}
function toggleVariant(v) {
    if (variantEdits[v.id]) delete variantEdits[v.id];
    else variantEdits[v.id] = { cost: v.cost, price: v.price };
}

const selectedCount = computed(() => Object.keys(productEdits).length + Object.keys(variantEdits).length);

const saving = ref(false);
function save() {
    if (!selectedCount.value) {
        toast('❌ কিছু সিলেক্ট করা হয়নি।');
        return;
    }
    saving.value = true;
    router.post(route('app.prices.bulkUpdate'), {
        products: Object.entries(productEdits).map(([id, v]) => ({ id: Number(id), cost: v.cost, price: v.price })),
        variants: Object.entries(variantEdits).map(([id, v]) => ({ id: Number(id), cost: v.cost, price: v.price })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            Object.keys(productEdits).forEach((k) => delete productEdits[k]);
            Object.keys(variantEdits).forEach((k) => delete variantEdits[k]);
        },
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <Head title="দাম আপডেট" />
    <AppLayout active="stock">
        <div class="pgttl">💰 দাম আপডেট</div>
        <div class="pgsub">প্রোডাক্ট, নির্দিষ্ট সাইজ/রং, বা পুরো গ্রুপের purchase ও sell দাম একসাথে বদলান</div>

        <HowToHint text="ক্যাটাগরি দিয়ে ফিল্টার করে একটা পুরো গ্রুপের প্রোডাক্ট একসাথে দেখুন, অথবা নাম/বারকোড দিয়ে খুঁজুন। যেগুলোর দাম বদলাতে চান সেগুলোর পাশে টিক দিন — প্রতিটার জন্য আলাদা নতুন দাম বসবে। সাইজ/রং-ভিত্তিক দাম বদলাতে প্রোডাক্টের পাশের ▶ চেপে ভ্যারিয়েন্ট দেখুন। সব শেষে নিচের বাটনে চেপে সেভ করুন।" />

        <div class="card" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
            <select v-model="categoryId" @change="applyFilters" style="flex:1;min-width:140px">
                <option value="">সব ক্যাটাগরি (গ্রুপ)</option>
                <option v-for="c in categories.filter(c => !c.parent_id)" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <input v-model="search" @keyup.enter="applyFilters" placeholder="🔍 নাম বা বারকোড দিয়ে খুঁজুন" style="flex:2;min-width:160px">
            <button class="btn ghost sm" style="width:auto;padding:0 14px" @click="applyFilters">খুঁজুন</button>
        </div>

        <div v-if="!products.length" class="empty"><div class="big">📦</div>কোনো প্রোডাক্ট পাওয়া যায়নি।</div>

        <template v-else>
            <div v-for="p in products" :key="p.id" class="row" style="flex-direction:column;align-items:stretch;cursor:default">
                <div style="display:flex;align-items:center;gap:8px">
                    <button v-if="p.variants.length" class="btn ghost sm" style="width:30px;height:30px;padding:0;flex-shrink:0" @click="toggleExpand(p.id)">{{ expanded[p.id] ? '▼' : '▶' }}</button>
                    <div style="flex-shrink:0;width:30px" v-else></div>
                    <div style="flex:1;cursor:pointer" @click="!p.is_branch_copy && toggleProduct(p)">
                        <b>{{ productEdits[p.id] ? '✅ ' : '' }}{{ p.name }}</b>
                        <div style="font-size:12.5px;color:var(--mut)">
                            বর্তমান — cost {{ money(p.cost) }}, sell {{ money(p.price) }}
                            <span v-if="p.is_branch_copy" style="color:var(--rose)"> · মূল দোকান থেকে নিয়ন্ত্রিত, এখানে বদলানো যাবে না</span>
                        </div>
                    </div>
                </div>

                <div v-if="productEdits[p.id]" style="display:flex;gap:8px;margin:8px 0 4px 38px">
                    <div class="field" style="flex:1;margin-bottom:0"><label>নতুন cost</label><input type="number" min="0" step="0.01" v-model.number="productEdits[p.id].cost"></div>
                    <div class="field" style="flex:1;margin-bottom:0"><label>নতুন sell দাম</label><input type="number" min="0" step="0.01" v-model.number="productEdits[p.id].price"></div>
                </div>

                <div v-if="expanded[p.id]" style="margin-left:38px;border-left:2px solid var(--line);padding-left:10px">
                    <div v-for="v in p.variants" :key="v.id" style="padding:6px 0">
                        <div style="display:flex;align-items:center;gap:8px;cursor:pointer" @click="toggleVariant(v)">
                            <span>{{ variantEdits[v.id] ? '✅' : '⬜' }}</span>
                            <div style="flex:1">
                                <b style="font-size:13.5px">{{ [v.size, v.color].filter(Boolean).join(', ') || 'ভ্যারিয়েন্ট' }}</b>
                                <div style="font-size:12px;color:var(--mut)">বর্তমান — cost {{ money(v.cost) }}, sell {{ money(v.price) }}</div>
                            </div>
                        </div>
                        <div v-if="variantEdits[v.id]" style="display:flex;gap:8px;margin:6px 0 2px 26px">
                            <div class="field" style="flex:1;margin-bottom:0"><label>নতুন cost</label><input type="number" min="0" step="0.01" v-model.number="variantEdits[v.id].cost"></div>
                            <div class="field" style="flex:1;margin-bottom:0"><label>নতুন sell দাম</label><input type="number" min="0" step="0.01" v-model.number="variantEdits[v.id].price"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="selectedCount" style="height:76px"></div>
            <div v-if="selectedCount" class="posbar" style="padding:10px">
                <button class="btn sm" :disabled="saving" @click="save">{{ saving ? 'সেভ হচ্ছে...' : `✅ ${selectedCount} টা আইটেমের দাম আপডেট করুন` }}</button>
            </div>
        </template>
    </AppLayout>
</template>
