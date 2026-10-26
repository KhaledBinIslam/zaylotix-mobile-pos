<script setup>
import { ref, computed } from 'vue';
import { colorGroups } from '@/support/productDisplay';
import { useI18n } from '@/composables/useI18n';

/**
 * SkyBuy-style color chip row -> size/price/qty table (docs/ARTICLE_SERIES_PLAN.md
 * §4), factored out so a scanned ARTICLE-level barcode (ambiguous — which
 * size/color?) can open this same picker in a focused popup instead of a
 * dead-end "pick a variant" error, on both POS and Online Order. Does not
 * add to the cart/order itself — emits `pick(variantId, qty)` and lets the
 * caller decide what that means (addToCart in POS, fill an order line in
 * Online Order).
 */
const props = defineProps({ product: Object });
const emit = defineEmits(['pick']);
const { t } = useI18n();

const groups = computed(() => colorGroups(props.product));
const selectedColor = ref(groups.value[0]?.color ?? null);
const activeGroup = computed(() => groups.value.find((g) => g.color === selectedColor.value) || groups.value[0] || null);

const qtyByVariant = ref({});
function qtyFor(v) {
    return qtyByVariant.value[v.id] ?? 1;
}
function stepQty(v, delta) {
    qtyByVariant.value[v.id] = Math.max(1, Math.min((qtyByVariant.value[v.id] ?? 1) + delta, v.stock || 1));
}
function pick(v) {
    emit('pick', v.id, qtyFor(v));
    qtyByVariant.value[v.id] = 1;
}

function money(n) {
    return '৳' + Math.round(n).toLocaleString('en-IN');
}
</script>

<template>
    <div>
        <div v-if="groups.length > 1 || groups[0]?.color" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
            <button
                v-for="g in groups" :key="g.color ?? '_'" type="button"
                class="upill" style="padding:4px;display:flex;align-items:center;gap:6px"
                :style="activeGroup?.color === g.color ? 'border-color:var(--sky);border-width:2px' : ''"
                @click="selectedColor = g.color"
            >
                <img v-if="g.photoUrl" :src="g.photoUrl" style="width:28px;height:28px;border-radius:6px;object-fit:cover">
                <div v-else style="width:28px;height:28px;border-radius:6px;background:var(--panel2)"></div>
                <span style="padding-right:4px">{{ g.color }}</span>
            </button>
        </div>
        <table v-if="activeGroup" style="width:100%;border-collapse:collapse;font-size:13px">
            <tbody>
                <tr v-for="v in activeGroup.variants" :key="v.id" style="border-top:1px solid var(--line)">
                    <td style="padding:7px 4px">{{ v.size }}</td>
                    <td style="padding:7px 4px">{{ money(v.price != null && Number(v.price) !== Number(product.price) ? v.price : product.price) }}</td>
                    <td style="padding:7px 4px">
                        <span v-if="v.stock <= 0" style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                        <div v-else style="display:flex;align-items:center;gap:6px">
                            <button type="button" class="btn ghost sm" style="padding:2px 8px" @click="stepQty(v, -1)">−</button>
                            <span style="min-width:18px;text-align:center">{{ qtyFor(v) }}</span>
                            <button type="button" class="btn ghost sm" style="padding:2px 8px" @click="stepQty(v, 1)">+</button>
                        </div>
                    </td>
                    <td style="padding:7px 4px;text-align:right">
                        <button v-if="v.stock > 0" type="button" class="btn sm" @click="pick(v)">{{ t('pos.add') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
