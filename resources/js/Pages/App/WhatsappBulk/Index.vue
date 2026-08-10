<script setup>
import { Head, useForm, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ connected: Boolean, customers: Array, recentLogs: Array, templates: { type: Array, default: () => [] } });
const { t } = useI18n();
const page = usePage();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
const filtered = computed(() => props.customers.filter((c) =>
    !q.value || c.name.toLowerCase().includes(q.value.toLowerCase()) || (c.phone || '').includes(q.value)
));

const selected = ref({}); // { customerId: true }
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length);
function toggle(c) {
    if (selected.value[c.id]) delete selected.value[c.id];
    else selected.value[c.id] = true;
}
function selectAll() {
    filtered.value.forEach((c) => (selected.value[c.id] = true));
}
// "নির্দিষ্ট গ্রুপে পাঠানো" — the one group specific enough to deserve its
// own one-tap shortcut (a due reminder is the single most common reason an
// owner would want to message exactly this subset and nobody else)
function selectDueOnly() {
    filtered.value.filter((c) => c.due > 0).forEach((c) => (selected.value[c.id] = true));
}
function clearSelection() {
    selected.value = {};
}

const form = useForm({ send_type: 'template', template_name: '', language_code: 'bn', message: '', customer_ids: [] });

function send() {
    form.customer_ids = Object.keys(selected.value).filter((id) => selected.value[id]).map(Number);
    if (!form.customer_ids.length) return;
    if (!confirm(`${form.customer_ids.length} ${t('whatsappBulk.confirmSend')}`)) return;
    form.post(route('app.whatsappBulk.send'), {
        preserveScroll: true,
        onSuccess: () => { clearSelection(); form.reset('message'); },
    });
}

// ---------------- saved templates (this shop's own, reusable snippets —
// separate from Meta's own approved Template concept, see
// WhatsappMessageTemplate's docblock) ----------------
const templatesForCurrentType = computed(() => props.templates.filter((tpl) => tpl.send_type === form.send_type));
function applyTemplate(tpl) {
    if (tpl.send_type === 'template') {
        form.template_name = tpl.template_name || '';
        form.language_code = tpl.language_code || 'bn';
    } else {
        form.message = tpl.message || '';
    }
}
function deleteTemplate(tpl) {
    if (!confirm(`"${tpl.label}" ${t('whatsappBulk.deleteTemplateConfirm')}`)) return;
    router.delete(route('app.whatsappTemplates.destroy', tpl.id), { preserveScroll: true });
}

const templateSheet = ref(false);
const templateForm = useForm({ label: '', send_type: 'text', template_name: '', language_code: 'bn', message: '' });
function openSaveTemplate() {
    templateForm.reset();
    templateForm.send_type = form.send_type;
    templateForm.template_name = form.template_name;
    templateForm.language_code = form.language_code;
    templateForm.message = form.message;
    templateSheet.value = true;
}
function saveTemplate() {
    templateForm.post(route('app.whatsappTemplates.store'), {
        preserveScroll: true,
        onSuccess: () => { templateSheet.value = false; templateForm.reset(); },
    });
}

// ---------------- new-number import (paste/type text and/or a CSV, both
// landing as real Customer records — see WhatsappBulkController::importContacts) ----------------
const importSheet = ref(false);
const importForm = useForm({ text: '', file: null });
function saveImport() {
    importForm.post(route('app.whatsappBulk.importContacts'), {
        preserveScroll: true,
        onSuccess: () => {
            importSheet.value = false;
            importForm.reset();
            // pre-selects exactly what was just imported — see the
            // 'importedCustomerIds' flash key HandleInertiaRequests shares
            (page.props.flash.importedCustomerIds || []).forEach((id) => (selected.value[id] = true));
        },
    });
}
</script>

<template>
    <Head :title="t('more.whatsappBulk')" />
    <AppLayout active="more">
        <div class="pgttl">📢 {{ t('more.whatsappBulk') }}</div>
        <div class="pgsub">{{ t('more.whatsappBulkSub') }}</div>

        <div v-if="!connected" class="card" style="background:var(--roseSoft);color:var(--rose);margin-bottom:16px">
            {{ t('whatsappBulk.notConnectedHint') }}
            <Link :href="route('app.more')" class="btn sm" style="margin-top:10px">{{ t('more.whatsappBusiness') }} →</Link>
        </div>

        <template v-else>
            <div class="card" style="margin-bottom:16px;background:var(--goldSoft);border-color:var(--gold2)">
                <b style="color:var(--gold2)">⚠ {{ t('whatsappBulk.windowWarningTitle') }}</b>
                <div style="font-size:12.5px;margin-top:4px;color:var(--tx)">{{ t('whatsappBulk.windowWarningBody') }}</div>
            </div>

            <div class="field">
                <label>{{ t('whatsappBulk.sendTypeLabel') }}</label>
                <div class="seg">
                    <button :class="{ on: form.send_type === 'template' }" @click="form.send_type = 'template'">{{ t('whatsappBulk.sendTypeTemplate') }}</button>
                    <button :class="{ on: form.send_type === 'text' }" @click="form.send_type = 'text'">{{ t('whatsappBulk.sendTypeText') }}</button>
                </div>
            </div>

            <div v-if="templatesForCurrentType.length" class="field">
                <label>{{ t('whatsappBulk.savedTemplatesLabel') }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <button
                        v-for="tpl in templatesForCurrentType" :key="tpl.id" type="button" class="btn sm ghost"
                        style="width:auto;padding:0 12px;display:inline-flex;align-items:center;gap:8px" @click="applyTemplate(tpl)"
                    >
                        {{ tpl.label }}
                        <span style="opacity:.5" :title="t('whatsappBulk.deleteTemplateConfirm')" @click.stop="deleteTemplate(tpl)">✕</span>
                    </button>
                </div>
            </div>

            <template v-if="form.send_type === 'template'">
                <div class="field">
                    <label>{{ t('whatsappBulk.templateNameLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('whatsappBulk.templateNameHint') }}</span></label>
                    <input v-model="form.template_name" placeholder="order_update">
                    <div v-if="form.errors.template_name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.template_name }}</div>
                </div>
                <div class="field">
                    <label>{{ t('whatsappBulk.languageCodeLabel') }}</label>
                    <input v-model="form.language_code" placeholder="bn">
                </div>
            </template>
            <template v-else>
                <div class="field">
                    <label>{{ t('whatsappBulk.messageLabel') }}</label>
                    <textarea v-model="form.message" rows="4" maxlength="1000" :placeholder="t('whatsappBulk.messagePlaceholder')"></textarea>
                    <div v-if="form.errors.message" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.message }}</div>
                </div>
            </template>
            <button
                type="button" class="btn sm ghost" style="margin-bottom:14px"
                :disabled="form.send_type === 'template' ? !form.template_name : !form.message"
                @click="openSaveTemplate"
            >
                💾 {{ t('whatsappBulk.saveAsTemplate') }}
            </button>

            <div class="hr"></div>

            <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
                <input v-model="q" :placeholder="t('whatsappBulk.searchCustomers')" style="flex:1;min-width:160px">
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="selectAll">{{ t('whatsappBulk.selectAll') }}</button>
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="selectDueOnly">{{ t('whatsappBulk.selectDueOnly') }}</button>
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="clearSelection">{{ t('whatsappBulk.clearSelection') }}</button>
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="importSheet = true">📥 {{ t('whatsappBulk.importContacts') }}</button>
            </div>

            <div v-for="c in filtered" :key="c.id" class="row" @click="toggle(c)" :class="{ incart: selected[c.id] }" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
                <div class="ava">{{ selected[c.id] ? '✅' : '👤' }}</div>
                <div class="mid">
                    <b>{{ c.name }}</b>
                    <span>{{ c.phone || t('whatsappBulk.noPhone') }} • {{ t('whatsappBulk.visitsCount', { n: c.visits }) }}<template v-if="c.due > 0"> • {{ t('pos.dueExists', { due: money(c.due) }) }}</template></span>
                </div>
            </div>
            <div v-if="!filtered.length" class="empty"><div class="big">👤</div>{{ t('whatsappBulk.noCustomers') }}</div>

            <div style="height:78px"></div>
            <div class="posbar">
                <button class="btn" :disabled="!selectedCount || form.processing || (form.send_type === 'template' ? !form.template_name : !form.message)" @click="send">
                    {{ form.processing ? t('pos.processing') : t('whatsappBulk.sendButton', { n: selectedCount }) }}
                </button>
            </div>

            <div v-if="recentLogs.length" style="margin-top:24px">
                <div class="sechead"><h2>{{ t('whatsappBulk.recentSendsTitle') }}</h2></div>
                <div v-for="log in recentLogs" :key="log.id" class="row" style="cursor:default">
                    <div class="ava">{{ log.send_type === 'template' ? '📋' : '💬' }}</div>
                    <div class="mid">
                        <b>{{ log.template_name || (log.message?.slice(0, 40) + (log.message?.length > 40 ? '…' : '')) }}</b>
                        <span>{{ log.user?.name }} • {{ log.sent_count }}/{{ log.recipients_count }} {{ t('whatsappBulk.sentLabel') }}<template v-if="log.failed_count > 0"> • {{ log.failed_count }} {{ t('whatsappBulk.failedLabel') }}</template></span>
                    </div>
                </div>
            </div>

            <!-- new-number import -->
            <Sheet v-model="importSheet" :title="t('whatsappBulk.importContacts')" :subtitle="t('whatsappBulk.importContactsSub')">
                <div class="card" style="margin-bottom:14px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                    ⚠ {{ t('whatsappBulk.consentReminder') }}
                </div>
                <div class="field">
                    <label>{{ t('whatsappBulk.pasteNumbersLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('whatsappBulk.pasteNumbersHint') }}</span></label>
                    <textarea v-model="importForm.text" rows="5" :placeholder="t('whatsappBulk.pasteNumbersPlaceholder')"></textarea>
                    <div v-if="importForm.errors.text" style="color:var(--rose);font-size:12px;margin-top:6px">{{ importForm.errors.text }}</div>
                </div>
                <div class="hr"></div>
                <a :href="route('app.whatsappBulk.importTemplate')" class="btn ghost sm" style="margin-bottom:14px;display:inline-block">{{ t('stock.downloadTemplate') }}</a>
                <div class="field">
                    <label>{{ t('stock.chooseFile') }}</label>
                    <input type="file" accept=".csv,text/csv" @change="importForm.file = $event.target.files[0]">
                    <div v-if="importForm.errors.file" style="color:var(--rose);font-size:12px;margin-top:6px">{{ importForm.errors.file }}</div>
                </div>
                <button class="btn" :disabled="importForm.processing || (!importForm.text && !importForm.file)" @click="saveImport">
                    {{ importForm.processing ? '...' : t('whatsappBulk.importSubmit') }}
                </button>
                <button class="btn ghost" style="margin-top:10px" @click="importSheet = false">{{ t('common.cancel') }}</button>
            </Sheet>

            <!-- save current message as a reusable template -->
            <Sheet v-model="templateSheet" :title="t('whatsappBulk.saveTemplateTitle')">
                <div class="field">
                    <label>{{ t('whatsappBulk.templateLabelLabel') }}</label>
                    <input v-model="templateForm.label" :placeholder="t('whatsappBulk.templateLabelPlaceholder')">
                    <div v-if="templateForm.errors.label" style="color:var(--rose);font-size:12px;margin-top:6px">{{ templateForm.errors.label }}</div>
                </div>
                <div class="card" style="margin-bottom:14px;font-size:12.5px;color:var(--mut)">
                    {{ templateForm.send_type === 'template' ? t('whatsappBulk.sendTypeTemplate') : t('whatsappBulk.sendTypeText') }} —
                    {{ templateForm.send_type === 'template' ? templateForm.template_name : templateForm.message }}
                </div>
                <button class="btn" :disabled="templateForm.processing || !templateForm.label" @click="saveTemplate">{{ templateForm.processing ? '...' : t('stock.save') }}</button>
                <button class="btn ghost" style="margin-top:10px" @click="templateSheet = false">{{ t('common.cancel') }}</button>
            </Sheet>
        </template>
    </AppLayout>
</template>
