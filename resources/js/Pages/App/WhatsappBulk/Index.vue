<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ connected: Boolean, customers: Array, recentLogs: Array });
const { t } = useI18n();

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

            <div class="hr"></div>

            <div style="display:flex;gap:8px;margin-bottom:10px">
                <input v-model="q" :placeholder="t('whatsappBulk.searchCustomers')" style="flex:1">
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="selectAll">{{ t('whatsappBulk.selectAll') }}</button>
                <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="clearSelection">{{ t('whatsappBulk.clearSelection') }}</button>
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
        </template>
    </AppLayout>
</template>
