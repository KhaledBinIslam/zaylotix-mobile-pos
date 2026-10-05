<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

const props = defineProps({ customers: Array, whatsappApiReady: Boolean });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
const filtered = computed(() => props.customers.filter((c) =>
    !q.value || c.name.toLowerCase().includes(q.value.toLowerCase()) || (c.phone || '').includes(q.value)
));

const selected = ref({});
const selectedIds = computed(() => Object.keys(selected.value).filter((id) => selected.value[id]).map(Number));
const selectedCount = computed(() => selectedIds.value.length);
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

// ---------------- bulk WhatsApp — two methods on purpose: a free wa.me
// link (works for every shop, no setup, text only, opens one tab per
// customer the owner has to tap Send in) vs the real Meta Cloud API
// (automatic, supports an image, but needs WhatsApp Business connected —
// see WhatsappBulkController::send(), reused as-is here) ----------------
const sendSheet = ref(false);
const method = ref('free');
const freeText = ref('');
function sendFree() {
    if (!freeText.value.trim()) { toast('❌ ' + t('customerList.writeMessageError')); return; }
    const targets = props.customers.filter((c) => selected.value[c.id] && c.phone);
    if (!targets.length) { toast('❌ ' + t('customerList.noPhoneSelected')); return; }

    targets.forEach((c, i) => {
        setTimeout(() => {
            const num = '88' + (c.phone || '').replace(/\D/g, '').replace(/^88/, '');
            const text = `প্রিয় ${c.name},\n\n${freeText.value}`;
            window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank');
        }, i * 600);
    });
    toast(`📤 ${targets.length} ${t('customerList.sendingOffer')}`);
    sendSheet.value = false;
    freeText.value = '';
    clearSelection();
}

const apiForm = useForm({ send_type: 'text', message: '', image: null, customer_ids: [] });
function sendApi() {
    if (!selectedCount.value) return;
    apiForm.customer_ids = selectedIds.value;
    apiForm.post(route('app.whatsappBulk.send'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { sendSheet.value = false; apiForm.reset(); clearSelection(); },
    });
}

function openSendSheet() {
    if (!selectedCount.value) return;
    method.value = props.whatsappApiReady ? 'api' : 'free';
    sendSheet.value = true;
}

// ---------------- export just the selected rows (Customer/Phone/Total
// Bought/Visits/Due), same shape as the full "due" export — see
// CustomerListController::exportSelected() ----------------
function exportSelected(fmt) {
    if (!selectedCount.value) return;
    const qs = selectedIds.value.map((id) => 'customer_ids[]=' + id).join('&');
    window.open(route('app.customerList.exportSelected') + '?' + qs + '&format=' + fmt, '_blank');
}
</script>

<template>
    <Head :title="t('customerList.title')" />
    <AppLayout active="customerList">
        <div class="pgttl">{{ t('customerList.title') }}</div>
        <div class="pgsub">{{ t('customerList.subtitle') }}</div>

        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
            <input v-model="q" :placeholder="t('customerList.search')" style="flex:1;min-width:160px">
            <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="selectAll">{{ t('customerList.selectAll') }}</button>
            <button class="btn sm ghost" style="width:auto;padding:0 14px" @click="clearSelection">{{ t('customerList.clearSelection') }}</button>
        </div>

        <div v-for="c in filtered" :key="c.id" class="row" @click="toggle(c)" :style="selected[c.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
            <div class="ava">{{ selected[c.id] ? '✅' : '👤' }}</div>
            <div class="mid">
                <b>{{ c.name }}</b>
                <span>📞 {{ c.phone || t('customerList.noPhone') }} • {{ t('customerList.totalBought') }} {{ money(c.total_spent) }}<template v-if="c.due > 0"> • <span style="color:var(--rose)">{{ t('customerList.due') }} {{ money(c.due) }}</span></template></span>
            </div>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">👤</div>{{ t('customerList.noCustomers') }}</div>

        <div v-if="selectedCount" style="height:78px"></div>
        <div v-if="selectedCount" class="posbar" style="display:flex;gap:8px">
            <button class="btn ghost sm" style="flex:1" @click="exportSelected('xlsx')">📤 {{ t('customerList.export') }}</button>
            <button class="btn sm" style="flex:2" @click="openSendSheet">💬 {{ t('customerList.sendWhatsapp', { n: selectedCount }) }}</button>
        </div>

        <Sheet v-model="sendSheet" :title="t('customerList.sendSheetTitle')">
            <div class="field">
                <div class="seg">
                    <button :class="{ on: method === 'free' }" @click="method = 'free'">{{ t('customerList.methodFree') }}</button>
                    <button :class="{ on: method === 'api' }" @click="method = 'api'">{{ t('customerList.methodApi') }}</button>
                </div>
            </div>

            <template v-if="method === 'free'">
                <div class="card" style="margin-bottom:14px;font-size:12.5px;color:var(--mut)">{{ t('customerList.methodFreeHint') }}</div>
                <div class="field">
                    <label>{{ t('customerList.messageLabel') }}</label>
                    <textarea v-model="freeText" rows="4" :placeholder="t('customerList.messagePlaceholder')"></textarea>
                </div>
                <button class="btn wa" :disabled="!freeText.trim()" @click="sendFree">{{ t('customerList.sendButton', { n: selectedCount }) }}</button>
            </template>

            <template v-else>
                <div v-if="!props.whatsappApiReady" class="card" style="margin-bottom:14px;background:var(--roseSoft);color:var(--rose);font-size:12.5px">
                    {{ t('customerList.methodApiNotReady') }}
                    <Link :href="route('app.more')" class="btn sm" style="margin-top:10px">{{ t('more.whatsappBusiness') }} →</Link>
                </div>
                <template v-else>
                    <div class="card" style="margin-bottom:14px;font-size:12.5px;color:var(--mut)">{{ t('customerList.methodApiHint') }}</div>
                    <div class="field">
                        <label>{{ t('customerList.messageLabel') }}</label>
                        <textarea v-model="apiForm.message" rows="4" maxlength="1000" :placeholder="t('customerList.messagePlaceholder')"></textarea>
                        <div v-if="apiForm.errors.message" style="color:var(--rose);font-size:12px;margin-top:6px">{{ apiForm.errors.message }}</div>
                    </div>
                    <div class="field">
                        <label>{{ t('customerList.imageLabel') }}</label>
                        <input type="file" accept="image/*" @change="apiForm.image = $event.target.files[0]">
                        <div v-if="apiForm.errors.image" style="color:var(--rose);font-size:12px;margin-top:6px">{{ apiForm.errors.image }}</div>
                    </div>
                    <button class="btn" :disabled="apiForm.processing || !apiForm.message.trim()" @click="sendApi">
                        {{ apiForm.processing ? '...' : t('customerList.sendButton', { n: selectedCount }) }}
                    </button>
                </template>
            </template>

            <button class="btn ghost" style="margin-top:10px" @click="sendSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
