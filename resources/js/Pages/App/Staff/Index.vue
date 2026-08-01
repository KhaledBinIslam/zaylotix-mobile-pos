<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ cashiers: Array, staffPermissions: Object });
const { t, lang } = useI18n();

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', phone: '', password: '', permissions: [] });

function openNew() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function openEdit(c) {
    editing.value = c;
    form.clearErrors();
    form.name = c.name;
    form.phone = c.phone;
    form.password = '';
    form.permissions = [...(c.permissions || [])];
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.staff.update', editing.value.id), { onSuccess: () => (sheet.value = false) });
    } else {
        form.post(route('app.staff.store'), { onSuccess: () => (sheet.value = false) });
    }
}
function removeCashier() {
    if (!editing.value || !confirm(`"${editing.value.name}"${t('cashier.removeConfirm')}`)) return;
    router.delete(route('app.staff.destroy', editing.value.id), { onSuccess: () => (sheet.value = false) });
}
const staffPermsByCategory = computed(() => {
    const groups = {};
    for (const [key, def] of Object.entries(props.staffPermissions || {})) {
        (groups[def.category] ??= []).push({ key, ...def });
    }
    return groups;
});
</script>

<template>
    <Head :title="t('nav.cashier')" />
    <AppLayout active="cashier">
        <div class="pgttl">{{ t('nav.cashier') }}</div>
        <div class="pgsub">{{ t('cashier.sheetSub') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('more.addCashier') }}</button>

        <div v-for="c in cashiers" :key="c.id" class="row" @click="openEdit(c)">
            <div class="ava">👤</div>
            <div class="mid"><b>{{ c.name }}</b><span>📞 {{ c.phone }} • {{ (c.permissions || []).length }} {{ t('cashier.accessCount') }}</span></div>
            <div class="end">›</div>
        </div>
        <div v-if="!cashiers.length" class="empty"><div class="big">👤</div>{{ t('cashier.noCashiers') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('cashier.editTitle') : t('cashier.sheetTitle')" :subtitle="t('cashier.sheetSub')">
            <div class="field">
                <label>{{ t('cashier.name') }}</label>
                <input v-model="form.name">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>
            <div class="field">
                <label>{{ t('cashier.phone') }}</label>
                <input v-model="form.phone">
                <div v-if="form.errors.phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.phone }}</div>
            </div>
            <div class="field">
                <label>{{ t('cashier.password') }} {{ editing ? t('cashier.passwordHint') : '' }}</label>
                <input v-model="form.password" type="password">
                <div v-if="form.errors.password" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.password }}</div>
            </div>

            <div class="field">
                <label>{{ t('cashier.accessLabel') }}</label>
                <div class="card" style="padding:12px">
                    <div v-for="(list, cat) in staffPermsByCategory" :key="cat" style="margin-bottom:10px">
                        <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:6px">{{ cat }}</div>
                        <label v-for="p in list" :key="p.key" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13.5px">
                            <input type="checkbox" :value="p.key" v-model="form.permissions" style="width:auto">
                            {{ lang === 'en' ? (p.label_en || p.label_bn) : p.label_bn }}
                        </label>
                    </div>
                </div>
            </div>

            <button class="btn" :disabled="form.processing" @click="save">
                {{ form.processing ? '...' : (editing ? t('cashier.update') : t('cashier.add')) }}
            </button>
            <button v-if="editing" class="btn rose" style="margin-top:10px" @click="removeCashier">{{ t('cashier.remove') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
