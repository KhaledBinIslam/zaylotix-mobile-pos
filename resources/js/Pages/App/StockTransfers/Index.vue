<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ siblings: Array, products: Array, recentTransfers: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
const filtered = computed(() => props.products.filter((p) =>
    !q.value || p.name.toLowerCase().includes(q.value.toLowerCase()) || (p.barcode || '').includes(q.value)
));

const form = useForm({ to_shop_id: props.siblings[0]?.id ?? '', product_id: '', qty: '' });
const selectedProduct = computed(() => props.products.find((p) => p.id === form.product_id));

function pick(p) {
    form.product_id = p.id;
    form.qty = '';
}
function send() {
    if (!form.to_shop_id || !form.product_id || !form.qty) return;
    form.post(route('app.stockTransfers.store'), {
        preserveScroll: true,
        onSuccess: () => { form.product_id = ''; form.qty = ''; },
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
                    <div><b>{{ selectedProduct.emoji }} {{ selectedProduct.name }}</b><div style="font-size:12px;color:var(--mut)">{{ t('stockTransfer.currentStock') }} {{ selectedProduct.stock }}</div></div>
                    <button class="btn sm ghost" style="width:auto" @click="form.product_id = ''">✕</button>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <input v-model.number="form.qty" type="number" :step="selectedProduct.sold_by_weight ? 0.001 : 1" min="0" :placeholder="t('stockTransfer.qtyPlaceholder')" style="flex:1">
                    <button class="btn sm" style="width:auto;padding:0 20px" :disabled="form.processing || !form.qty" @click="send">{{ form.processing ? '...' : t('stockTransfer.sendButton') }}</button>
                </div>
                <div v-if="form.errors.qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.qty }}</div>
                <div v-if="form.errors.product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.product_id }}</div>
            </div>

            <input v-model="q" :placeholder="t('pos.searchPlaceholder')" style="margin-bottom:12px">
            <div v-for="p in filtered" :key="p.id" class="row" @click="pick(p)">
                <div class="ava">{{ p.emoji || '📦' }}</div>
                <div class="mid"><b>{{ p.name }}</b><span>{{ t('stockTransfer.currentStock') }} {{ p.stock }}</span></div>
                <div class="end">›</div>
            </div>
            <div v-if="!filtered.length" class="empty"><div class="big">📦</div>{{ t('pos.noProducts') }}</div>

            <div v-if="recentTransfers.length" style="margin-top:24px">
                <div class="sechead"><h2>{{ t('stockTransfer.recentTitle') }}</h2></div>
                <div v-for="tr in recentTransfers" :key="tr.id" class="row" style="cursor:default">
                    <div class="ava">🚚</div>
                    <div class="mid">
                        <b>{{ tr.product_name }} × {{ tr.qty }}</b>
                        <span>{{ tr.from_shop?.name }} → {{ tr.to_shop?.name }} • {{ tr.user?.name }}</span>
                    </div>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
