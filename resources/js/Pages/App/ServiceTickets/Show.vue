<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ ticket: Object, total: Number, history: Array, products: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const fmt = (dt) => dt ? new Date(dt).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
const fmtDate = (d) => d ? d.slice(0, 10) : '';

const isOpen = computed(() => !['delivered', 'cancelled'].includes(props.ticket.status));

const statusLabel = (s) => ({
    received: t('service.statusReceived'), in_progress: t('service.statusInProgress'), waiting_parts: t('service.statusWaitingParts'),
    ready: t('service.statusReady'), delivered: t('service.statusDelivered'), cancelled: t('service.statusCancelled'),
}[s] || s);

const statusColor = (s) => ({
    received: 'var(--mut)', in_progress: 'var(--amber)', waiting_parts: 'var(--amber)',
    ready: 'var(--green)', delivered: 'var(--green)', cancelled: 'var(--rose)',
}[s] || 'var(--mut)');

// --- attach/change customer (see docs/ENTERPRISE_GAP_REPORT.md's "সার্ভিস
// টিকিটে পরে কাস্টমার যুক্ত করা" gap — intake doesn't force a phone
// number up front, so a walk-in device needs a way to attach one later,
// e.g. right before billing, if the repair is going to leave a due). ---
const customerSheet = ref(false);
const customerForm = useForm({ customer_phone: props.ticket.customer?.phone || '', customer_name: props.ticket.customer?.name || '' });
function saveCustomer() {
    customerForm.patch(route('app.serviceTickets.updateCustomer', props.ticket.id), {
        preserveScroll: true,
        onSuccess: () => (customerSheet.value = false),
    });
}

// --- status/technician/promise-date update ---
const statusForm = useForm({ status: props.ticket.status, technician_name: props.ticket.technician_name || '', promised_at: fmtDate(props.ticket.promised_at) });
function saveStatus() {
    statusForm.patch(route('app.serviceTickets.update', props.ticket.id), { preserveScroll: true });
}

// --- add item (part from inventory, or a free-typed labor/custom line) ---
const itemMode = ref('part'); // 'part' | 'labor'
const itemForm = useForm({ product_id: '', is_labor: false, label: '', qty: 1, cost: '', price: '' });
function pickProduct() {
    const p = props.products.find((p) => p.id === Number(itemForm.product_id));
    if (p) { itemForm.price = p.price; itemForm.cost = p.cost; }
}
function addItem() {
    itemForm.is_labor = itemMode.value === 'labor';
    if (itemMode.value === 'labor') itemForm.product_id = '';
    itemForm.post(route('app.serviceTickets.items.store', props.ticket.id), {
        preserveScroll: true,
        onSuccess: () => { itemForm.reset(); itemForm.qty = 1; },
    });
}
function removeItem(item) {
    if (!confirm(t('service.removeItemConfirm'))) return;
    router.delete(route('app.serviceTickets.items.destroy', item.id), { preserveScroll: true });
}

function cancelTicket() {
    if (!confirm(t('service.cancelConfirm'))) return;
    router.post(route('app.serviceTickets.cancel', props.ticket.id));
}

// --- billing ---
const billSheet = ref(false);
const payMode = ref('cash');
const billForm = useForm({ payments: [] });
function submitBill() {
    billForm.payments = payMode.value === 'credit' ? [] : [{ method: payMode.value, amount: props.total }];
    billForm.post(route('app.serviceTickets.bill', props.ticket.id));
}
</script>

<template>
    <Head :title="`#${ticket.id} ${ticket.device_label}`" />
    <AppLayout active="more">
        <div class="pgttl">🛠️ #{{ ticket.id }} {{ ticket.device_label }}</div>
        <div class="pgsub"><b :style="{ color: statusColor(ticket.status) }">{{ statusLabel(ticket.status) }}</b></div>

        <div class="card" style="margin-bottom:14px">
            <div v-if="ticket.customer" style="margin-bottom:8px">
                <b>{{ t('service.customer') }}</b> {{ ticket.customer.name }} ({{ ticket.customer.phone }})
                <button v-if="isOpen" class="btn ghost sm" style="width:auto;padding:2px 10px;margin-left:6px;font-size:11px" @click="customerSheet = true">{{ t('common.edit') }}</button>
            </div>
            <div v-else-if="isOpen" style="margin-bottom:8px">
                <button class="btn ghost sm" style="width:auto" @click="customerSheet = true">👤 {{ t('service.addCustomer') }}</button>
            </div>
            <div v-if="ticket.serial" style="margin-bottom:8px"><b>IMEI</b> {{ ticket.serial.imei }}</div>
            <div v-if="ticket.problem_description" style="margin-bottom:8px"><b>{{ t('service.problem') }}</b> {{ ticket.problem_description }}</div>
            <div style="font-size:12px;color:var(--mut)">{{ t('service.receivedAt') }} {{ fmt(ticket.received_at) }}</div>
            <div v-if="ticket.is_warranty_claim" style="margin-top:6px;color:var(--gold2);font-weight:700">🛡️ {{ t('service.isWarrantyClaim') }}</div>
            <div v-if="ticket.sale" style="margin-top:8px"><Link :href="route('app.sales.show', ticket.sale.id)">🧾 {{ ticket.sale.invoice_no }}</Link></div>
        </div>

        <template v-if="isOpen">
            <div class="sechead"><h2>{{ t('service.statusAndAssignment') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div class="field">
                    <label>{{ t('service.status') }}</label>
                    <select v-model="statusForm.status">
                        <option value="received">{{ t('service.statusReceived') }}</option>
                        <option value="in_progress">{{ t('service.statusInProgress') }}</option>
                        <option value="waiting_parts">{{ t('service.statusWaitingParts') }}</option>
                        <option value="ready">{{ t('service.statusReady') }}</option>
                    </select>
                </div>
                <div class="f2">
                    <div class="field"><label>{{ t('service.technician') }}</label><input v-model="statusForm.technician_name"></div>
                    <div class="field"><label>{{ t('service.promisedDate') }}</label><input v-model="statusForm.promised_at" type="date"></div>
                </div>
                <button class="btn sm" :disabled="statusForm.processing" @click="saveStatus">{{ t('service.saveButton') }}</button>
            </div>
        </template>

        <div class="sechead"><h2>{{ t('service.items') }}</h2></div>
        <div class="card" style="padding:0;margin-bottom:14px">
            <div v-for="(item, i) in ticket.items" :key="item.id" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                <div class="mid"><b>{{ item.label }}</b><span>{{ item.is_labor ? t('service.labor') : t('service.part') }} • {{ item.qty }} × {{ money(item.price) }}</span></div>
                <div class="end">
                    <b>{{ money(item.price * item.qty) }}</b>
                    <button v-if="isOpen" class="btn sm ghost" style="width:auto;margin-top:4px;color:var(--rose);border-color:var(--rose)" @click="removeItem(item)">{{ t('service.removeButton') }}</button>
                </div>
            </div>
            <div v-if="!ticket.items.length" class="empty" style="padding:20px">{{ t('service.noItems') }}</div>
        </div>
        <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
            <b>{{ t('service.total') }}</b><b style="font-size:19px;color:var(--gold)">{{ money(total) }}</b>
        </div>

        <template v-if="isOpen">
            <div class="sechead"><h2>{{ t('service.addItem') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div class="seg" style="margin-bottom:10px">
                    <button :class="{ on: itemMode === 'part' }" @click="itemMode = 'part'">{{ t('service.part') }}</button>
                    <button :class="{ on: itemMode === 'labor' }" @click="itemMode = 'labor'">{{ t('service.labor') }}</button>
                </div>
                <div v-if="itemMode === 'part'" class="field">
                    <label>{{ t('service.chooseProduct') }}</label>
                    <select v-model="itemForm.product_id" @change="pickProduct">
                        <option :value="''">{{ t('stock.selectPlaceholder') }}</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} ({{ p.stock }})</option>
                    </select>
                </div>
                <div v-else class="field">
                    <label>{{ t('service.labelPlaceholder') }}</label>
                    <input v-model="itemForm.label" :placeholder="t('service.laborExample')">
                </div>
                <div class="f2">
                    <div class="field"><label>{{ t('service.qty') }}</label><input v-model.number="itemForm.qty" type="number" step="1" min="0.001"></div>
                    <div class="field"><label>{{ t('service.price') }}</label><input v-model.number="itemForm.price" type="number" step="0.01"></div>
                </div>
                <div v-if="itemForm.errors.label" style="color:var(--rose);font-size:12px;margin-bottom:8px">{{ itemForm.errors.label }}</div>
                <button class="btn sm" :disabled="itemForm.processing || !itemForm.price" @click="addItem">{{ t('service.addItemButton') }}</button>
            </div>

            <button class="btn" style="margin-bottom:10px" :disabled="!ticket.items.length" @click="billSheet = true">{{ t('service.billButton') }}</button>
            <button class="btn ghost" style="color:var(--rose);border-color:var(--rose)" @click="cancelTicket">{{ t('service.cancelTicket') }}</button>
        </template>

        <template v-if="history.length">
            <div class="sechead"><h2>{{ t('service.history') }}</h2></div>
            <Link v-for="h in history" :key="h.id" :href="route('app.serviceTickets.show', h.id)" class="row">
                <div class="ava">🛠️</div>
                <div class="mid"><b>#{{ h.id }} {{ h.device_label }}</b><span>{{ h.customer?.name }} • {{ fmt(h.received_at) }}</span></div>
                <div class="end"><b :style="{ color: statusColor(h.status) }">{{ statusLabel(h.status) }}</b></div>
            </Link>
        </template>

        <Sheet v-model="billSheet" :title="t('service.billButton')">
            <div class="field">
                <label>{{ t('pos.payment') }}</label>
                <div class="seg">
                    <button :class="{ on: payMode === 'cash' }" @click="payMode = 'cash'">{{ t('pay.cash') }}</button>
                    <button :class="{ on: payMode === 'bkash' }" @click="payMode = 'bkash'">{{ t('pay.bkash') }}</button>
                    <button :class="{ on: payMode === 'nagad' }" @click="payMode = 'nagad'">{{ t('pay.nagad') }}</button>
                    <button :class="{ on: payMode === 'credit' }" @click="payMode = 'credit'">{{ t('pay.credit') }}</button>
                </div>
            </div>
            <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
                <b>{{ t('pos.grandTotal') }}</b><b style="font-size:19px;color:var(--gold)">{{ money(total) }}</b>
            </div>
            <div v-if="billForm.errors.payments" style="color:var(--rose);font-size:12px;margin-bottom:8px">{{ billForm.errors.payments }}</div>
            <button class="btn" :disabled="billForm.processing" @click="submitBill">{{ billForm.processing ? '...' : t('service.billButton') }}</button>
        </Sheet>

        <Sheet v-model="customerSheet" :title="t('service.addCustomer')">
            <div class="field">
                <label>{{ t('due.mobile') }}</label>
                <input v-model="customerForm.customer_phone" placeholder="01812-111222">
                <div v-if="customerForm.errors.customer_phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ customerForm.errors.customer_phone }}</div>
            </div>
            <div class="field">
                <label>{{ t('due.name') }} <span style="color:var(--dim);font-weight:400">({{ t('stock.optional') }})</span></label>
                <input v-model="customerForm.customer_name" :placeholder="t('due.namePlaceholder')">
            </div>
            <button class="btn" :disabled="customerForm.processing" @click="saveCustomer">{{ customerForm.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="customerSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
