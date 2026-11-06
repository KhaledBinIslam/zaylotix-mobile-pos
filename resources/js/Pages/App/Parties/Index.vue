<script setup>
/**
 * Corporate parties (feature: parties — SEAMAN Phase 5). A party is an
 * existing customer with party_type set — same due, same payments; these
 * pages only add the party-shaped views. List is due-first (server
 * sorted), searchable by company / person / phone.
 */
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ parties: Array, totalDue: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
const filtered = computed(() => {
    const needle = q.value.trim().toLowerCase();
    if (!needle) return props.parties;
    return props.parties.filter((p) =>
        (p.name || '').toLowerCase().includes(needle)
        || (p.company_name || '').toLowerCase().includes(needle)
        || (p.contact_person || '').toLowerCase().includes(needle)
        || (p.phone || '').includes(needle));
});

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', party_type: 'company', phone: '', company_name: '', contact_person: '', address: '', notes: '' });

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
    form.party_type = p.party_type;
    form.phone = p.phone || '';
    form.company_name = p.company_name || '';
    form.contact_person = p.contact_person || '';
    form.address = p.address || '';
    form.notes = p.notes || '';
    sheet.value = true;
}
function save() {
    if (editing.value) form.patch(route('app.parties.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    else form.post(route('app.parties.store'), { onSuccess: () => { sheet.value = false; } });
}
</script>

<template>
    <Head :title="t('party.title')" />
    <AppLayout active="more">
        <div class="pgttl">🏢 {{ t('party.title') }}</div>
        <div class="pgsub">{{ t('party.subtitle') }}</div>
        <HowToHint :text="t('party.howTo')" />

        <div class="card" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="font-size:12px;color:var(--mut)">{{ t('party.totalDue') }}</div>
                <div style="font-size:22px;font-weight:800;color:var(--rose)">{{ money(totalDue) }}</div>
            </div>
            <button class="btn" style="width:auto;padding:10px 16px" @click="openNew">+ {{ t('party.new') }}</button>
        </div>

        <div class="field" style="margin-bottom:10px">
            <input v-model="q" :placeholder="t('party.searchPh')">
        </div>

        <Link v-for="p in filtered" :key="p.id" :href="route('app.parties.show', p.id)" class="card" style="margin-bottom:8px;display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit">
            <div class="ava">{{ p.party_type === 'company' ? '🏢' : '👤' }}</div>
            <div style="flex:1;min-width:0">
                <b>{{ p.company_name || p.name }}</b>
                <div style="font-size:12px;color:var(--mut)">
                    <span v-if="p.contact_person">{{ p.contact_person }} · </span>{{ p.phone || 'Nil' }}
                </div>
            </div>
            <div style="text-align:right">
                <div :style="{ fontWeight: 700, color: p.due > 0 ? 'var(--rose)' : 'var(--grn)' }">{{ money(p.due) }}</div>
                <div style="font-size:11px;color:var(--mut)">{{ t('party.due') }}</div>
            </div>
            <button class="btn sm ghost" style="width:auto;padding:6px 10px;font-size:12px" @click.prevent="openEdit(p)">{{ t('emp.edit') }}</button>
        </Link>
        <div v-if="!filtered.length" class="empty"><div class="big">🏢</div>{{ t('party.none') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('party.editTitle') : t('party.new')">
            <div class="field">
                <label>{{ t('party.type') }}</label>
                <select v-model="form.party_type">
                    <option value="company">{{ t('party.typeCompany') }}</option>
                    <option value="person">{{ t('party.typePerson') }}</option>
                </select>
            </div>
            <div class="field"><label>{{ t('party.name') }} *</label><input v-model="form.name" :placeholder="t('party.namePh')"></div>
            <div v-if="form.errors.name" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ form.errors.name }}</div>
            <div v-if="form.party_type === 'company'" class="field"><label>{{ t('party.companyName') }}</label><input v-model="form.company_name"></div>
            <div class="field"><label>{{ t('party.contactPerson') }}</label><input v-model="form.contact_person"></div>
            <div class="field"><label>{{ t('party.phone') }}</label><input v-model="form.phone" inputmode="tel"></div>
            <div v-if="form.errors.phone" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ form.errors.phone }}</div>
            <div class="field"><label>{{ t('party.address') }}</label><input v-model="form.address"></div>
            <div class="field"><label>{{ t('party.notes') }}</label><textarea v-model="form.notes" rows="2"></textarea></div>
            <button class="btn" :disabled="form.processing" @click="save">{{ t('emp.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
