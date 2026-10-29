<script setup>
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReceiptMemo from '@/Components/ReceiptMemo.vue';
import BarcodeLabelCard from '@/Components/BarcodeLabelCard.vue';
import { useI18n } from '@/composables/useI18n';
import { LABEL_SIZE_PRESETS, labelSizeMm } from '@/support/labelSizes';

const page = usePage();
const shop = computed(() => page.props.shop);
const platformLogoUrl = computed(() => page.props.platformLogoUrl);
const { t } = useI18n();

const businessForm = useForm({
    email: shop.value?.email || '', website: shop.value?.website || '',
    trade_license_no: shop.value?.trade_license_no || '', tin_no: shop.value?.tin_no || '',
    label_header: shop.value?.label_header || '',
});
function saveBusinessInfo() {
    businessForm.patch(route('app.settings.businessInfo'), { preserveScroll: true });
}

const ms = shop.value?.memo_settings || {};
const memoForm = useForm({
    show_email: ms.show_email, show_website: ms.show_website, show_bin: ms.show_bin,
    show_trade_license: ms.show_trade_license, show_tin: ms.show_tin, show_cashier: ms.show_cashier,
    show_customer_phone: ms.show_customer_phone, show_payment_method: ms.show_payment_method,
    show_tendered_change: ms.show_tendered_change, show_vat_inclusive_note: ms.show_vat_inclusive_note,
    show_exchange_policy: ms.show_exchange_policy, exchange_policy_text: ms.exchange_policy_text || '',
    show_shop_qr: ms.show_shop_qr, paper_width: ms.paper_width,
});
function saveMemoSettings() {
    memoForm.patch(route('app.settings.memo'), { preserveScroll: true });
}

const ls = shop.value?.label_settings || {};
const labelForm = useForm({
    size: ls.size, custom_width_mm: ls.custom_width_mm, custom_height_mm: ls.custom_height_mm,
    per_row: ls.per_row, show_shop_name: ls.show_shop_name, show_product_name: ls.show_product_name,
    show_variant: ls.show_variant, price_mode: ls.price_mode, show_barcode_number: ls.show_barcode_number,
});
function saveLabelSettings() {
    labelForm.patch(route('app.settings.label'), { preserveScroll: true });
}
const labelSizeDisplay = computed(() => labelSizeMm(labelForm));

// sample data — the live preview never touches real sales/products
const sampleShop = computed(() => ({
    ...shop.value,
    memo_settings: { ...memoForm.data() },
}));
const sampleSale = {
    id: 999999, invoice_no: 'DEMO-0001', date: '২০২৬-১০-১১', time: '১৪:৩০',
    subtotal: 1250, discount: 50, service_charge: 0, vat: 0, total: 1200,
    payment_mode: 'cash', coupon_code: null, points_redeemed: 0, points_earned: 0,
    customer: { name: 'করিম মিয়া', phone: '01712345678' },
    user: { name: 'ক্যাশিয়ার আসিফ' },
    payments: [{ id: 1, method: 'cash', amount: 1200 }],
    items: [
        { id: 1, product_name: 'নমুনা পণ্য ১', variant_label: '42, কালো', unit_label: null, qty: 1, price: 800 },
        { id: 2, product_name: 'নমুনা পণ্য ২', variant_label: null, unit_label: null, qty: 2, price: 225 },
    ],
};
const sampleLabel = { name: 'নমুনা পণ্য', variantLabel: '42, কালো', barcode: '2000000012345', price: 850, discount_price: 799 };
</script>

<template>
    <Head title="মেমো ও লেবেল সেটিংস" />
    <AppLayout active="more">
        <div class="pgttl">🧾 মেমো ও লেবেল সেটিংস</div>
        <div class="pgsub">কোন কোন তথ্য মেমো আর বারকোড লেবেলে দেখাবে, নিজের মতো করে সাজান</div>

        <div class="card" style="margin-bottom:14px">
            <h3 style="margin:0 0 10px">দোকানের অতিরিক্ত তথ্য</h3>
            <div class="field"><label>ইমেইল</label><input v-model="businessForm.email" placeholder="shop@example.com"></div>
            <div class="field"><label>ওয়েবসাইট / Facebook পেজ</label><input v-model="businessForm.website" placeholder="facebook.com/yourshop"></div>
            <div class="field"><label>ট্রেড লাইসেন্স নম্বর</label><input v-model="businessForm.trade_license_no"></div>
            <div class="field"><label>TIN নম্বর (ঐচ্ছিক)</label><input v-model="businessForm.tin_no"></div>
            <!-- business-level: only a main shop can set this (Shop::labelHeader() always
                 resolves a branch through its own main shop) — a branch's own value here
                 would never actually be used, so the input only makes sense on the main shop -->
            <div v-if="!shop?.parent_shop_id" class="field">
                <label>লেবেল হেডার (বারকোড স্টিকারে দোকানের নামের জায়গায়, ঐচ্ছিক)</label>
                <input v-model="businessForm.label_header" placeholder="যেমন: Baby Park & Liberty">
            </div>
            <div v-else style="font-size:11.5px;color:var(--mut);margin:6px 0">লেবেল হেডার মূল দোকানের সেটিংস থেকে আসে।</div>
            <button class="btn sm" style="margin-top:6px" :disabled="businessForm.processing" @click="saveBusinessInfo">সংরক্ষণ করুন</button>
        </div>

        <div class="grid-2col" style="display:grid;grid-template-columns:1fr;gap:14px">
            <div class="card">
                <h3 style="margin:0 0 10px">মেমোতে কী দেখাবে</h3>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_email"> ইমেইল</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_website"> ওয়েবসাইট/Facebook</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_bin"> BIN (VAT চালু থাকলে)</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_trade_license"> ট্রেড লাইসেন্স নম্বর</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_tin"> TIN নম্বর</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_cashier"> ক্যাশিয়ারের নাম</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_customer_phone"> কাস্টমারের ফোন নম্বর</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_payment_method"> পেমেন্ট মাধ্যম</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_tendered_change"> কত দিল / ফেরত</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_vat_inclusive_note"> "দামের মধ্যে VAT অন্তর্ভুক্ত" লেখা</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_exchange_policy"> এক্সচেঞ্জ/রিটার্ন নীতি লেখা</label>
                <div v-if="memoForm.show_exchange_policy" class="field" style="margin-top:6px">
                    <textarea v-model="memoForm.exchange_policy_text" placeholder="বিক্রিত পণ্য ৭ দিনের মধ্যে রসিদসহ এক্সচেঞ্জযোগ্য" rows="2"></textarea>
                </div>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_shop_qr"> দোকানের ওয়েবসাইট/Facebook পেজের QR কোড (উপরের "ওয়েবসাইট / Facebook পেজ" ঘরে লিংক দিন)</label>

                <div class="field" style="margin-top:10px">
                    <label>কাগজের সাইজ</label>
                    <div class="seg">
                        <button type="button" :class="{ on: memoForm.paper_width === '58mm' }" @click="memoForm.paper_width = '58mm'">৫৮mm</button>
                        <button type="button" :class="{ on: memoForm.paper_width === '80mm' }" @click="memoForm.paper_width = '80mm'">৮০mm</button>
                        <button type="button" :class="{ on: memoForm.paper_width === 'a4' }" @click="memoForm.paper_width = 'a4'">A4</button>
                    </div>
                </div>
                <button class="btn sm" style="margin-top:10px" :disabled="memoForm.processing" @click="saveMemoSettings">মেমো সেটিংস সংরক্ষণ করুন</button>
            </div>

            <div class="card" style="display:flex;justify-content:center;background:var(--surface2, #f5f5f5)">
                <ReceiptMemo :shop="sampleShop" :sale="sampleSale" :platform-logo-url="platformLogoUrl" id-suffix="-preview" :show-barcode-and-qr="false" />
            </div>
        </div>

        <div class="grid-2col" style="display:grid;grid-template-columns:1fr;gap:14px;margin-top:14px">
            <div class="card">
                <h3 style="margin:0 0 10px">বারকোড লেবেলে কী দেখাবে</h3>
                <div class="field">
                    <label>লেবেল সাইজ</label>
                    <div class="seg" style="flex-wrap:wrap">
                        <button type="button" v-for="(preset, key) in LABEL_SIZE_PRESETS" :key="key" :class="{ on: labelForm.size === key }" @click="labelForm.size = key">{{ preset.label }}</button>
                        <button type="button" :class="{ on: labelForm.size === 'custom' }" @click="labelForm.size = 'custom'">Custom</button>
                    </div>
                </div>
                <div v-if="labelForm.size === 'custom'" style="display:flex;gap:8px">
                    <div class="field"><label>Width (mm)</label><input v-model.number="labelForm.custom_width_mm" type="number" min="10" max="100"></div>
                    <div class="field"><label>Height (mm)</label><input v-model.number="labelForm.custom_height_mm" type="number" min="10" max="100"></div>
                </div>
                <div v-if="labelForm.errors.custom_width_mm" style="color:var(--rose);font-size:12px">{{ labelForm.errors.custom_width_mm }}</div>

                <div class="field">
                    <label>প্রতি সারিতে কয়টা লেবেল</label>
                    <div class="seg">
                        <button type="button" :class="{ on: labelForm.per_row === 1 }" @click="labelForm.per_row = 1">১টা</button>
                        <button type="button" :class="{ on: labelForm.per_row === 2 }" @click="labelForm.per_row = 2">২টা</button>
                    </div>
                </div>

                <label class="chk"><input type="checkbox" v-model="labelForm.show_shop_name"> দোকানের নাম</label>
                <label class="chk"><input type="checkbox" v-model="labelForm.show_product_name"> পণ্যের নাম</label>
                <label class="chk"><input type="checkbox" v-model="labelForm.show_variant"> সাইজ/কালার</label>
                <label class="chk"><input type="checkbox" v-model="labelForm.show_barcode_number"> বারকোডের নিচে নম্বর</label>

                <div class="field" style="margin-top:6px">
                    <label>দাম</label>
                    <div class="seg">
                        <button type="button" :class="{ on: labelForm.price_mode === 'both' }" @click="labelForm.price_mode = 'both'">নিয়মিত + ছাড়</button>
                        <button type="button" :class="{ on: labelForm.price_mode === 'single' }" @click="labelForm.price_mode = 'single'">একটি দাম</button>
                        <button type="button" :class="{ on: labelForm.price_mode === 'none' }" @click="labelForm.price_mode = 'none'">দাম নেই</button>
                    </div>
                </div>
                <button class="btn sm" style="margin-top:10px" :disabled="labelForm.processing" @click="saveLabelSettings">লেবেল সেটিংস সংরক্ষণ করুন</button>
                <Link :href="route('app.barcodeLabels.index')" class="btn sm ghost" style="margin-top:8px;display:block;text-align:center">লেবেল প্রিন্ট পেজে যান →</Link>
            </div>

            <div class="card" style="display:flex;flex-direction:column;align-items:center;gap:8px;background:var(--surface2, #f5f5f5)">
                <div style="font-size:12px;color:var(--mut)">{{ labelSizeDisplay.width }}×{{ labelSizeDisplay.height }}mm</div>
                <div :style="{ width: labelSizeDisplay.width + 'mm' }">
                    <BarcodeLabelCard :label="sampleLabel" :shop="shop" :settings="labelForm" dom-id="label-preview-svg" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.chk { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 14px; cursor: pointer; }
</style>
