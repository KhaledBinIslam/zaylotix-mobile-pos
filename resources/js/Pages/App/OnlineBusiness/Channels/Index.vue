<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ channels: Array });
const { t } = useI18n();

const PLATFORM_TYPES = ['facebook', 'website', 'daraz', 'whatsapp', 'catalog', 'other'];
const platformEmoji = { facebook: '📘', website: '🌐', daraz: '🛍️', whatsapp: '💬', catalog: '📒', other: '🏷️' };
const platformLabel = (p) => t('channel.platform.' + p);

const sheet = ref(false);
const editing = ref(null);
const logoPreview = ref(null);
const form = useForm({
    name: '', platform_type: 'facebook', logo: null, remove_logo: false, active: true,
    order_prefix: '', platform_fee_percent: '', gateway_fee_percent: '',
});

function openNew() {
    editing.value = null;
    form.reset();
    logoPreview.value = null;
    sheet.value = true;
}
function openEdit(c) {
    editing.value = c;
    form.name = c.name;
    form.platform_type = c.platform_type;
    form.logo = null;
    form.remove_logo = false;
    form.active = c.active;
    form.order_prefix = c.order_prefix;
    form.platform_fee_percent = c.platform_fee_percent;
    form.gateway_fee_percent = c.gateway_fee_percent;
    logoPreview.value = null;
    sheet.value = true;
}
function pickLogo(e) {
    const file = e.target.files[0];
    form.logo = file || null;
    form.remove_logo = false;
    logoPreview.value = file ? URL.createObjectURL(file) : null;
}
function removeLogo() {
    form.logo = null;
    form.remove_logo = true;
    logoPreview.value = null;
}
function save() {
    if (editing.value) {
        form.put(route('app.channels.update', editing.value.id), { onSuccess: () => (sheet.value = false) });
    } else {
        form.post(route('app.channels.store'), { onSuccess: () => (sheet.value = false) });
    }
}
function destroy(c) {
    if (!confirm(`"${c.name}" ${t('channel.deleteConfirm')}`)) return;
    useForm({}).delete(route('app.channels.destroy', c.id));
}
</script>

<template>
    <Head :title="t('channel.title')" />
    <AppLayout active="channels">
        <div class="pgttl">{{ t('channel.title') }}</div>
        <div class="pgsub">{{ t('channel.subtitle') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('channel.add') }}</button>

        <div v-for="c in channels" :key="c.id" class="card" style="margin-bottom:10px">
            <div style="display:flex;align-items:center;gap:10px">
                <div class="ava" style="font-size:20px">
                    <img v-if="c.logo_url" :src="c.logo_url" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
                    <template v-else>{{ platformEmoji[c.platform_type] || '🏷️' }}</template>
                </div>
                <div style="flex:1">
                    <b>{{ c.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ platformLabel(c.platform_type) }} • {{ c.order_prefix }}</div>
                </div>
                <span class="pill" :class="c.active ? 'mint' : 'rose'">{{ c.active ? t('channel.active') : t('channel.inactive') }}</span>
            </div>
            <div class="btnrow" style="margin-top:10px">
                <button class="btn sm ghost" style="flex:1" @click="openEdit(c)">{{ t('channel.edit') }}</button>
                <Link :href="route('app.channels.prices', c.id)" class="btn sm ghost" style="flex:1;text-align:center">{{ t('channel.prices') }}</Link>
                <button class="btn sm rose" @click="destroy(c)">🗑️</button>
            </div>
        </div>

        <div v-if="!channels.length" class="empty"><div class="big">🌐</div>{{ t('channel.noChannels') }}</div>

        <Sheet v-model="sheet" :title="editing ? editing.name : t('channel.addSheetTitle')">
            <div class="field">
                <label>{{ t('channel.name') }}</label>
                <input v-model="form.name" :placeholder="t('channel.namePlaceholder')">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>

            <div class="field">
                <label>{{ t('channel.platformType') }}</label>
                <div class="seg" style="margin-top:4px;flex-wrap:wrap">
                    <button v-for="p in PLATFORM_TYPES" :key="p" type="button" :class="{ on: form.platform_type === p }" @click="form.platform_type = p">
                        {{ platformEmoji[p] }} {{ platformLabel(p) }}
                    </button>
                </div>
            </div>

            <div class="field">
                <label>{{ t('channel.logo') }} <span style="color:var(--dim);font-weight:400">({{ t('stock.optional') }})</span></label>
                <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
                    <img v-if="logoPreview || (editing?.logo_url && !form.remove_logo)" :src="logoPreview || editing.logo_url" style="width:48px;height:48px;object-fit:cover;border-radius:50%">
                    <input type="file" accept="image/*" @change="pickLogo">
                </div>
                <button v-if="logoPreview || (editing?.logo_url && !form.remove_logo)" class="btn sm ghost" type="button" style="margin-top:6px" @click="removeLogo">{{ t('stock.removePhoto') }}</button>
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="form.active" type="checkbox">
                    {{ t('channel.active') }}
                </label>
            </div>

            <div class="field">
                <label>{{ t('channel.orderPrefix') }} <span style="color:var(--dim);font-weight:400">{{ t('channel.orderPrefixHint') }}</span></label>
                <input v-model="form.order_prefix" placeholder="FB1-">
                <div v-if="form.errors.order_prefix" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.order_prefix }}</div>
            </div>

            <div class="f2">
                <div class="field">
                    <label>{{ t('channel.platformFee') }}</label>
                    <input v-model="form.platform_fee_percent" type="number" step="0.01" min="0" max="100" placeholder="0">
                    <div v-if="form.errors.platform_fee_percent" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.platform_fee_percent }}</div>
                </div>
                <div class="field">
                    <label>{{ t('channel.gatewayFee') }}</label>
                    <input v-model="form.gateway_fee_percent" type="number" step="0.01" min="0" max="100" placeholder="0">
                    <div v-if="form.errors.gateway_fee_percent" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.gateway_fee_percent }}</div>
                </div>
            </div>

            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
