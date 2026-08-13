<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ setting: Object });

const preview = ref(null);
const form = useForm({ logo: null });
const reminderForm = useForm({ reminder_days: props.setting.reminder_days });
const whatsappForm = useForm({ whatsapp_contact: props.setting.whatsapp_contact });

function saveReminderDays() {
    reminderForm.patch(route('admin.siteSettings.reminderDays'), { preserveScroll: true });
}

function saveWhatsappContact() {
    whatsappForm.patch(route('admin.siteSettings.whatsappContact'), { preserveScroll: true });
}

function pickFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    form.logo = file;
    preview.value = URL.createObjectURL(file);
}

function upload() {
    form.post(route('admin.siteSettings.update'), {
        forceFormData: true,
        onSuccess: () => { form.reset(); preview.value = null; },
    });
}

function remove() {
    if (!confirm('Remove the platform logo? Every shop\'s memo/label will fall back to the plain "zaylotix.com" text credit.')) return;
    router.delete(route('admin.siteSettings.destroy'));
}
</script>

<template>
    <Head title="Site Settings" />
    <AdminLayout active="siteSettings">
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Site Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Platform-wide branding — this logo appears in this admin panel's sidebar and, as the "product owner" credit, on every shop's printed memo and barcode labels (in place of the plain "zaylotix.com" text).</p>
        </div>

        <div class="bg-white border rounded-xl p-6 max-w-lg">
            <div class="flex items-center gap-5 mb-5">
                <div class="w-20 h-20 rounded-lg border flex items-center justify-center bg-gray-50 overflow-hidden shrink-0">
                    <img v-if="preview || setting.logo_url" :src="preview || setting.logo_url" class="max-w-full max-h-full object-contain">
                    <span v-else class="text-gray-300 text-xs text-center px-2">No logo</span>
                </div>
                <div class="text-sm text-gray-500">
                    <div v-if="setting.logo_url">Currently live on every shop's printed output.</div>
                    <div v-else>Not set — shops currently show the plain "zaylotix.com" text credit instead.</div>
                </div>
            </div>

            <label class="block text-sm font-medium text-gray-700 mb-1">Upload a new logo</label>
            <input type="file" accept="image/png,image/jpeg,image/webp" class="text-sm w-full mb-1" @change="pickFile">
            <div class="text-xs text-gray-400 mb-3">PNG/JPG/WebP, up to 1MB. A logo with a transparent background prints cleanest on receipts.</div>
            <div v-if="form.errors.logo" class="text-rose-600 text-xs mb-3">{{ form.errors.logo }}</div>

            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" :disabled="!form.logo || form.processing" @click="upload">
                    {{ form.processing ? 'Uploading...' : 'Upload' }}
                </button>
                <button v-if="setting.logo_url" class="px-4 py-2 rounded-lg border border-rose-200 text-rose-600 font-semibold text-sm" @click="remove">
                    Remove
                </button>
            </div>
        </div>

        <div class="bg-white border rounded-xl p-6 max-w-lg mt-6">
            <h2 class="font-semibold text-gray-900 mb-1">Subscription Reminder</h2>
            <p class="text-sm text-gray-500 mb-4">How many days before a shop's subscription/trial expires the daily reminder notification starts firing (see the "⏰ পেমেন্ট রিমাইন্ডার" notification shop owners see). Runs once a day at 08:00.</p>

            <label class="block text-sm font-medium text-gray-700 mb-1">Days before expiry</label>
            <div class="flex items-center gap-2">
                <input v-model.number="reminderForm.reminder_days" type="number" min="1" max="30" class="border rounded-lg px-3 py-2 text-sm w-24">
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" :disabled="reminderForm.processing" @click="saveReminderDays">
                    {{ reminderForm.processing ? 'Saving...' : 'Save' }}
                </button>
            </div>
            <div v-if="reminderForm.errors.reminder_days" class="text-rose-600 text-xs mt-2">{{ reminderForm.errors.reminder_days }}</div>
        </div>

        <div class="bg-white border rounded-xl p-6 max-w-lg mt-6">
            <h2 class="font-semibold text-gray-900 mb-1">Landing Page WhatsApp Button</h2>
            <p class="text-sm text-gray-500 mb-4">The number the public landing page's "WhatsApp" button opens a chat with, for shops interested in signing up. Digits only, with country code (e.g. 8801XXXXXXXXX), no + or spaces.</p>

            <label class="block text-sm font-medium text-gray-700 mb-1">WhatsApp number</label>
            <div class="flex items-center gap-2">
                <input v-model="whatsappForm.whatsapp_contact" placeholder="8801XXXXXXXXX" class="border rounded-lg px-3 py-2 text-sm w-48">
                <button class="px-4 py-2 rounded-lg [background:#7C3AED] text-white font-semibold text-sm" :disabled="whatsappForm.processing" @click="saveWhatsappContact">
                    {{ whatsappForm.processing ? 'Saving...' : 'Save' }}
                </button>
            </div>
            <div v-if="whatsappForm.errors.whatsapp_contact" class="text-rose-600 text-xs mt-2">{{ whatsappForm.errors.whatsapp_contact }}</div>
        </div>
    </AdminLayout>
</template>
