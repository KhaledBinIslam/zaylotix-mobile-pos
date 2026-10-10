<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ siblings: Array, products: Array, recentTransfers: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const fmt = (dt) => new Date(dt).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

const q = ref('');
const filtered = computed(() => props.products.filter((p) =>
    !q.value || p.name.toLowerCase().includes(q.value.toLowerCase()) || (p.barcode || '').includes(q.value)
));

const form = useForm({ to_shop_id: props.siblings[0]?.id ?? '', product_id: '', lines: [] });
const selectedProduct = computed(() => props.products.find((p) => p.id === form.product_id));
const variantQty = ref({}); // product_variant_id -> qty string, only used while a variant product is selected

function pick(p) {
    if (p.batch_or_serial_tracked) return; // disabled in the template too — see the row's own hint
    form.product_id = p.id;
    variantQty.value = {};
    if (!p.variants?.length) {
        form.lines = [{ product_variant_id: null, qty: '' }];
    } else {
        form.lines = [];
    }
}

// Rebuilds `lines` from the per-variant qty inputs whenever one changes —
// only variants with a qty actually typed in are included, so a variant
// product can transfer just one or two sizes without the others at 0.
watch(variantQty, (val) => {
    if (!selectedProduct.value?.variants?.length) return;
    form.lines = Object.entries(val)
        .filter(([, v]) => Number(v) > 0)
        .map(([variantId, v]) => ({ product_variant_id: Number(variantId), qty: Number(v) }));
}, { deep: true });

const totalQty = computed(() => form.lines.reduce((sum, l) => sum + Number(l.qty || 0), 0));

function send() {
    if (!form.to_shop_id || !form.product_id || !form.lines.length) return;
    form.post(route('app.stockTransfers.store'), {
        preserveScroll: true,
        onSuccess: () => { form.product_id = ''; form.lines = []; variantQty.value = {}; },
    });
}
</script>

<template>
    <Head :title="t('stockTransfer.title')" />
    <AppLayout active="more">
        <div class="pgttl">🚚 {{ t('stockTransfer.title') }}</div>
        <div class="pgsub">{{ t('stockTransfer.subtitle') }}</div>

        <div v-if="!siblings.length" class="empty"><div class="big">🏬</div>{{ t('stockTransfer.noSiblingsHint') }}</div>

        <template v-else>
            <div class="field">
                <label>{{ t('stockTransfer.toBranchLabel') }}</label>
                <select v-model.number="form.to_shop_id">
                    <option v-for="s in siblings" :key="s.id" :value="s.id">{{ s.is_warehouse ? '🏭' : '🏬' }} {{ s.name }}<template v-if="s.area"> — {{ s.area }}</template></option>
                </select>
            </div>

            <div v-if="selectedProduct" class="card" style="margin-bottom:12px;background:var(--goldSoft);border-color:var(--gold2)">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div><b>{{ selectedProduct.emoji }} {{ selectedProduct.name }}</b><div v-if="!selectedProduct.variants?.length" style="font-size:12px;color:var(--mut)">{{ t('stockTransfer.currentStock') }} {{ qty(selectedProduct.stock) }}</div></div>
                    <button class="btn sm ghost" style="width:auto" @click="form.product_id = ''">✕</button>
                </div>

                <template v-if="selectedProduct.variants?.length">
                    <div style="margin-top:10px;font-size:12px;color:var(--mut)">{{ t('stockTransfer.perVariantHint') }}</div>
                    <div v-for="v in selectedProduct.variants" :key="v.id" style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:8px">
                        <div style="flex:1"><b>{{ v.label }}</b><div style="font-size:12px;color:var(--mut)">{{ t('stockTransfer.currentStock') }} {{ qty(v.stock) }}</div></div>
                        <input v-model.number="variantQty[v.id]" type="number" step="1" min="0" :max="v.stock" style="width:90px" placeholder="0">
                    </div>
                    <div style="margin-top:10px;font-weight:700">{{ t('stockTransfer.totalLabel') }}: {{ qty(totalQty) }}</div>
                    <button class="btn sm" style="margin-top:8px;width:100%" :disabled="form.processing || !totalQty" @click="send">{{ form.processing ? '...' : t('stockTransfer.sendButton') }}</button>
                </template>
                <template v-else>
                    <div style="display:flex;gap:8px;margin-top:10px">
                        <input v-model.number="form.lines[0].qty" type="number" :step="selectedProduct.sold_by_weight ? 0.001 : 1" min="0" :placeholder="t('stockTransfer.qtyPlaceholder')" style="flex:1">
                        <button class="btn sm" style="width:auto;padding:0 20px" :disabled="form.processing || !form.lines[0]?.qty" @click="send">{{ form.processing ? '...' : t('stockTransfer.sendButton') }}</button>
                    </div>
                </template>
                <div v-if="form.errors.lines" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.lines }}</div>
                <div v-if="form.errors.product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.product_id }}</div>
            </div>

            <input v-model="q" :placeholder="t('pos.searchPlaceholder')" style="margin-bottom:12px">
            <div v-for="p in filtered" :key="p.id" class="row" :style="p.batch_or_serial_tracked ? 'opacity:.55;cursor:default' : ''" @click="pick(p)">
                <div class="ava">{{ p.emoji || '📦' }}</div>
                <div class="mid">
                    <b>{{ p.name }}</b>
                    <span v-if="p.batch_or_serial_tracked">{{ t('stockTransfer.batchSerialBlocked') }}</span>
                    <span v-else>{{ t('stockTransfer.currentStock') }} {{ qty(p.stock) }}</span>
                </div>
                <div class="end">›</div>
            </div>
            <div v-if="!filtered.length" class="empty"><div class="big">📦</div>{{ t('pos.noProducts') }}</div>

            <div v-if="recentTransfers.length" style="margin-top:24px">
                <div class="sechead"><h2>{{ t('stockTransfer.recentTitle') }}</h2></div>
                <div v-for="tr in recentTransfers" :key="tr.id" class="row" style="cursor:default">
                    <div class="ava">🚚</div>
                    <div class="mid">
                        <b>{{ tr.product_name }}{{ tr.variant_label ? ' ('+tr.variant_label+')' : '' }} × {{ qty(tr.qty) }}</b>
                        <span>{{ tr.from_shop?.name }} → {{ tr.to_shop?.name }} • {{ tr.user?.name }} • {{ fmt(tr.created_at) }}</span>
                    </div>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
