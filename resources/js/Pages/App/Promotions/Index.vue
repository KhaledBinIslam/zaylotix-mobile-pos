<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ promotions: Array, products: Array });
const { t } = useI18n();

const page = usePage();
const shop = computed(() => page.props.shop);
const hasLoyaltyPoints = computed(() => (page.props.features || []).includes('loyalty_points'));
const loyaltyForm = useForm({ loyalty_earn_rate: shop.value?.loyalty_earn_rate ?? 1, loyalty_point_value: shop.value?.loyalty_point_value ?? 1 });
function saveLoyalty() {
    loyaltyForm.patch(route('app.settings.loyalty'), { preserveScroll: true });
}

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const sheet = ref(false);
const editing = ref(null);
const form = useForm({
    name: '', type: 'bogo', active: true, starts_at: '', expires_at: '',
    code: '', discount_type: 'percent', discount_value: '', min_purchase: '', usage_limit: '',
    buy_product_id: '', buy_qty: '', get_product_id: '', get_qty: '', get_discount_percent: '100',
});

function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function openEdit(p) {
    editing.value = p;
    form.clearErrors();
    form.name = p.name;
    form.type = p.type;
    form.active = p.active;
    form.starts_at = p.starts_at || '';
    form.expires_at = p.expires_at || '';
    form.code = p.code || '';
    form.discount_type = p.discount_type || 'percent';
    form.discount_value = p.discount_value || '';
    form.min_purchase = p.min_purchase || '';
    form.usage_limit = p.usage_limit || '';
    form.buy_product_id = p.buy_product_id || '';
    form.buy_qty = p.buy_qty || '';
    form.get_product_id = p.get_product_id || '';
    form.get_qty = p.get_qty || '';
    form.get_discount_percent = p.get_discount_percent || '100';
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.promotions.update', editing.value.id), { onSuccess: () => (sheet.value = false) });
    } else {
        form.post(route('app.promotions.store'), { onSuccess: () => (sheet.value = false) });
    }
}
function removePromotion() {
    if (!editing.value || !confirm(t('promotion.removeConfirm'))) return;
    router.delete(route('app.promotions.destroy', editing.value.id), { onSuccess: () => (sheet.value = false) });
}

function summary(p) {
    if (p.type === 'coupon') {
        const val = p.discount_type === 'percent' ? `${p.discount_value}%` : money(p.discount_value);
        return `${t('promotion.code')}: ${p.code} — ${val} ${t('promotion.off')}`;
    }
    const buy = p.buyProduct ? `${p.buyProduct.emoji || ''} ${p.buyProduct.name}` : '?';
    const get = p.getProduct ? `${p.getProduct.emoji || ''} ${p.getProduct.name}` : buy;
    const pct = Number(p.get_discount_percent) === 100 ? t('promotion.free') : `${p.get_discount_percent}% ${t('promotion.off')}`;
    return `${buy} ${p.buy_qty} ${t('promotion.buyThen')} ${get} ${p.get_qty} ${pct}`;
}
</script>

<template>
    <Head :title="t('nav.promotions')" />
    <AppLayout active="promotions">
        <div class="pgttl">{{ t('nav.promotions') }}</div>
        <div class="pgsub">{{ t('promotion.subtitle') }}</div>

        <template v-if="hasLoyaltyPoints">
            <div class="sechead"><h2>{{ t('promotion.loyaltySection') }}</h2></div>
            <div class="card" style="margin-bottom:16px">
                <div style="font-size:12.5px;color:var(--mut);margin-bottom:10px">{{ t('promotion.loyaltyHint') }}</div>
                <div class="f2">
                    <div class="field">
                        <label>{{ t('promotion.earnRate') }} <span style="color:var(--dim);font-weight:400">{{ t('promotion.earnRateHint') }}</span></label>
                        <input v-model="loyaltyForm.loyalty_earn_rate" type="number">
                        <div v-if="loyaltyForm.errors.loyalty_earn_rate" style="color:var(--rose);font-size:12px;margin-top:6px">{{ loyaltyForm.errors.loyalty_earn_rate }}</div>
                    </div>
                    <div class="field">
                        <label>{{ t('promotion.pointValue') }} <span style="color:var(--dim);font-weight:400">{{ t('promotion.pointValueHint') }}</span></label>
                        <input v-model="loyaltyForm.loyalty_point_value" type="number">
                        <div v-if="loyaltyForm.errors.loyalty_point_value" style="color:var(--rose);font-size:12px;margin-top:6px">{{ loyaltyForm.errors.loyalty_point_value }}</div>
                    </div>
                </div>
                <button class="btn sm" :disabled="loyaltyForm.processing" @click="saveLoyalty">{{ loyaltyForm.processing ? '...' : t('stock.save') }}</button>
            </div>
        </template>

        <div class="sechead"><h2>{{ t('promotion.offersSection') }}</h2></div>
        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('promotion.add') }}</button>

        <div v-for="p in promotions" :key="p.id" class="row" @click="openEdit(p)">
            <div class="ava">{{ p.type === 'coupon' ? '🎟️' : '🎁' }}</div>
            <div class="mid">
                <b>{{ p.name }}</b>
                <span>{{ summary(p) }}</span>
            </div>
            <div class="end">
                <span class="pill" :class="p.active ? 'mint' : 'mut'">{{ p.active ? t('promotion.active') : t('promotion.inactive') }}</span>
                <span style="font-size:11px;color:var(--mut);margin-top:4px">{{ p.used_count }} {{ t('promotion.timesUsed') }}</span>
            </div>
        </div>
        <div v-if="!promotions.length" class="empty"><div class="big">🎁</div>{{ t('promotion.noPromotions') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('promotion.editTitle') : t('promotion.addTitle')">
            <div class="field">
                <label>{{ t('promotion.name') }}</label>
                <input v-model="form.name" :placeholder="t('promotion.namePlaceholder')">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>

            <div class="field">
                <label>{{ t('promotion.type') }}</label>
                <div class="seg">
                    <button type="button" :class="{ on: form.type === 'bogo' }" @click="form.type = 'bogo'">{{ t('promotion.typeBogo') }}</button>
                    <button type="button" :class="{ on: form.type === 'coupon' }" @click="form.type = 'coupon'">{{ t('promotion.typeCoupon') }}</button>
                </div>
            </div>

            <template v-if="form.type === 'bogo'">
                <div style="color:var(--dim);font-size:12px;margin-bottom:10px">{{ t('promotion.bogoHint') }}</div>
                <div class="f2">
                    <div class="field">
                        <label>{{ t('promotion.buyProduct') }}</label>
                        <select v-model="form.buy_product_id">
                            <option value="">{{ t('damage.selectPlaceholder') }}</option>
                            <option v-for="pr in products" :key="pr.id" :value="pr.id">{{ pr.emoji }} {{ pr.name }}</option>
                        </select>
                        <div v-if="form.errors.buy_product_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.buy_product_id }}</div>
                    </div>
                    <div class="field">
                        <label>{{ t('promotion.buyQty') }}</label>
                        <input v-model="form.buy_qty" type="number">
                        <div v-if="form.errors.buy_qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.buy_qty }}</div>
                    </div>
                </div>
                <div class="f2">
                    <div class="field">
                        <label>{{ t('promotion.getProduct') }} <span style="color:var(--dim);font-weight:400">{{ t('promotion.getProductHint') }}</span></label>
                        <select v-model="form.get_product_id">
                            <option value="">{{ t('promotion.sameAsAbove') }}</option>
                            <option v-for="pr in products" :key="pr.id" :value="pr.id">{{ pr.emoji }} {{ pr.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>{{ t('promotion.getQty') }}</label>
                        <input v-model="form.get_qty" type="number">
                        <div v-if="form.errors.get_qty" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.get_qty }}</div>
                    </div>
                </div>
                <div class="field">
                    <label>{{ t('promotion.getDiscountPercent') }} <span style="color:var(--dim);font-weight:400">{{ t('promotion.getDiscountPercentHint') }}</span></label>
                    <input v-model="form.get_discount_percent" type="number">
                </div>
            </template>

            <template v-else>
                <div class="field">
                    <label>{{ t('promotion.code') }}</label>
                    <input v-model="form.code" :placeholder="t('promotion.codePlaceholder')" style="text-transform:uppercase">
                    <div v-if="form.errors.code" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.code }}</div>
                </div>
                <div class="f2">
                    <div class="field">
                        <label>{{ t('promotion.discountType') }}</label>
                        <select v-model="form.discount_type">
                            <option value="percent">{{ t('promotion.percent') }}</option>
                            <option value="fixed">{{ t('promotion.fixedTaka') }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>{{ t('promotion.discountValue') }}</label>
                        <input v-model="form.discount_value" type="number">
                        <div v-if="form.errors.discount_value" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.discount_value }}</div>
                    </div>
                </div>
                <div class="f2">
                    <div class="field"><label>{{ t('promotion.minPurchase') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.min_purchase" type="number"></div>
                    <div class="field"><label>{{ t('promotion.usageLimit') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.usage_limit" type="number"></div>
                </div>
            </template>

            <div class="f2">
                <div class="field"><label>{{ t('promotion.startsAt') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.starts_at" type="date"></div>
                <div class="field"><label>{{ t('promotion.expiresAt') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.expires_at" type="date"></div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin:10px 0">
                <input type="checkbox" v-model="form.active" style="width:auto">
                {{ t('promotion.activeLabel') }}
            </label>

            <button class="btn" :disabled="form.processing" @click="save">
                {{ form.processing ? '...' : t('stock.save') }}
            </button>
            <button v-if="editing" class="btn rose" style="margin-top:10px" @click="removePromotion">{{ t('promotion.remove') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
