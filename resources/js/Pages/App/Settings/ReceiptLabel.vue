<script setup>
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import { computed, ref, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ReceiptMemo from '@/Components/ReceiptMemo.vue';
import BarcodeLabelCard from '@/Components/BarcodeLabelCard.vue';
import { useI18n } from '@/composables/useI18n';
import { LABEL_SIZE_PRESETS, labelSizeMm } from '@/support/labelSizes';
import { thermalModuleWidth, mmToPx } from '@/support/barcodeFit';
import { useBluetoothPrinterScan } from '@/composables/useBluetoothPrinterScan';

const props = defineProps({ longestLabelBarcode: { type: Object, default: null } });

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
    show_logo: ms.show_logo ?? true, show_frame: ms.show_frame ?? false,
});
function saveMemoSettings() {
    memoForm.patch(route('app.settings.memo'), { preserveScroll: true });
}

const ls = shop.value?.label_settings || {};
const labelForm = useForm({
    size: ls.size, custom_width_mm: ls.custom_width_mm, custom_height_mm: ls.custom_height_mm,
    per_row: ls.per_row, show_shop_name: ls.show_shop_name, show_product_name: ls.show_product_name,
    show_variant: ls.show_variant, price_mode: ls.price_mode, show_barcode_number: ls.show_barcode_number,
    // Phase 41 (docs/LABEL_PRINT_PLAN.md) — thermal-roll print mode
    print_mode: ls.print_mode || 'classic', label_gap_mm: ls.label_gap_mm ?? 2,
    offset_x_mm: ls.offset_x_mm ?? 0, offset_y_mm: ls.offset_y_mm ?? 0,
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

// --- Phase 41: printer calibration test print (docs/LABEL_PRINT_PLAN.md §৬) ---
// One exact-size page: edge border + center cross + 1mm scale + this shop's
// longest encoded barcode — printed once to dial in offset_x/y and prove
// the hardest label scans on the actual XP-365B roll.
const calibrating = ref(false);
const calibSize = computed(() => labelSizeMm(labelForm));
const calibSample = computed(() => props.longestLabelBarcode || { encoded: 'ZV12345', text: 'ZV12345' });
const calibCss = computed(() => `
@page { size: ${calibSize.value.width}mm ${calibSize.value.height}mm; margin: 0; }
@media print {
    #app { display: none !important; }
    #calib-overlay { position: static !important; }
    .calib-card { transform: translate(${Number(labelForm.offset_x_mm) || 0}mm, ${Number(labelForm.offset_y_mm) || 0}mm); }
}
`);
function printCalibration() {
    window.print();
}
async function openCalibration() {
    calibrating.value = true;
    await nextTick();
    const el = document.getElementById('calib-barcode-svg');
    if (!el) return;
    const { default: JsBarcode } = await import('jsbarcode');
    const fit = thermalModuleWidth(calibSample.value.encoded, calibSize.value.width - 5);
    try {
        JsBarcode(el, calibSample.value.encoded, {
            format: 'CODE128', margin: 0, height: mmToPx(6), fontSize: 7,
            displayValue: true, text: calibSample.value.text, width: mmToPx(fit.moduleMm),
        });
    } catch (e) { /* leave blank */ }
}
// tick marks every 1mm along the top edge, taller every 5mm — SVG viewBox is
// in mm so coordinates map 1:1 to the physical label
const calibTicks = computed(() => {
    const ticks = [];
    for (let x = 1; x < calibSize.value.width; x++) ticks.push({ x, len: x % 5 === 0 ? 2 : 1 });
    return ticks;
});

// --- Phase 42: Bluetooth printer scan diagnostic (docs/LABEL_PRINT_PLAN.md) ---
// Read-only probe — before building a real BLE printing feature, this
// answers "can this phone's browser even reach the printer over BLE at
// all." No bytes are sent to the printer here.
const bleScan = useBluetoothPrinterScan();
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
                <label class="chk"><input type="checkbox" v-model="memoForm.show_logo"> দোকানের লোগো</label>
                <label class="chk"><input type="checkbox" v-model="memoForm.show_frame"> পুরো মেমোর চারপাশে ফ্রেম/বর্ডার</label>
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

                <!-- Phase 41 — docs/LABEL_PRINT_PLAN.md: 'classic' = আগের
                     আউটপুট হুবহু; 'thermal' = XP-365B জাতীয় থার্মাল রোলের
                     জন্য fixed-dot বারকোড + offset সংশোধন -->
                <div class="field">
                    <label>প্রিন্ট মোড</label>
                    <div class="seg">
                        <button type="button" :class="{ on: labelForm.print_mode === 'classic' }" @click="labelForm.print_mode = 'classic'">সাধারণ</button>
                        <button type="button" :class="{ on: labelForm.print_mode === 'thermal' }" @click="labelForm.print_mode = 'thermal'">থার্মাল রোল (XP-365B)</button>
                    </div>
                </div>
                <template v-if="labelForm.print_mode === 'thermal'">
                    <div style="display:flex;gap:8px">
                        <div class="field"><label>লেবেলের ফাঁক (mm)</label><input v-model.number="labelForm.label_gap_mm" type="number" min="0" max="10" step="0.5"></div>
                        <div class="field"><label>Offset X (mm)</label><input v-model.number="labelForm.offset_x_mm" type="number" min="-5" max="5" step="0.5"></div>
                        <div class="field"><label>Offset Y (mm)</label><input v-model.number="labelForm.offset_y_mm" type="number" min="-5" max="5" step="0.5"></div>
                    </div>
                    <div style="font-size:11.5px;color:var(--mut);margin:2px 0 6px">ছাপা একপাশে সরে গেলে Offset দিয়ে ঠিক করুন — নিচের "লেবেল টেস্ট প্রিন্ট" দিয়ে মেপে নিন।</div>
                    <button type="button" class="btn sm ghost" style="margin-bottom:8px" @click="openCalibration">📐 লেবেল টেস্ট প্রিন্ট (ক্যালিব্রেশন)</button>

                    <!-- Phase 42 — diagnostic only, proves whether this
                         phone's browser can reach the printer over
                         Bluetooth at all before any real printing feature
                         gets built on top of it -->
                    <div class="field" style="margin-top:10px;border-top:1px dashed var(--line);padding-top:10px">
                        <label>📱 ফোন থেকে সরাসরি Bluetooth প্রিন্ট (পরীক্ষামূলক)</label>
                        <div style="font-size:11.5px;color:var(--mut);margin-bottom:8px">আগে শুধু যাচাই করা হবে প্রিন্টারটা এভাবে ধরা যায় কিনা — এখনই কিছু প্রিন্ট হবে না।</div>
                        <button type="button" class="btn sm ghost" :disabled="bleScan.scanning.value" @click="bleScan.scan">
                            {{ bleScan.scanning.value ? 'খোঁজা হচ্ছে...' : '🔵 Bluetooth প্রিন্টার যাচাই করুন' }}
                        </button>
                        <div v-if="bleScan.error.value" style="color:var(--rose);font-size:12.5px;margin-top:8px">⚠️ {{ bleScan.error.value }}</div>
                        <div v-if="bleScan.result.value" class="card" style="margin-top:10px;font-size:12.5px">
                            <div style="font-weight:700;margin-bottom:6px">📟 {{ bleScan.result.value.deviceName }}</div>
                            <template v-if="bleScan.result.value.services.length">
                                <div v-for="svc in bleScan.result.value.services" :key="svc.uuid" style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--line)">
                                    <div style="font-weight:600">{{ svc.label || svc.uuid }}</div>
                                    <div style="font-size:11px;color:var(--mut);word-break:break-all">{{ svc.uuid }}</div>
                                    <div v-for="c in svc.characteristics" :key="c.uuid" style="margin-top:4px;padding-left:10px">
                                        <span :style="{ color: c.writable ? 'var(--green)' : 'var(--mut)' }">{{ c.writable ? '✅ লেখা যায়' : '· শুধু পড়া যায়' }}</span>
                                        <span style="font-size:10.5px;color:var(--dim);word-break:break-all"> {{ c.uuid }}</span>
                                    </div>
                                </div>
                                <div style="color:var(--green);font-weight:700">✅ এই ফলাফল আমাকে (Claude-কে) স্ক্রিনশট দিয়ে পাঠান — পরের ধাপ বানাতে এটাই লাগবে।</div>
                            </template>
                            <div v-else style="color:var(--rose)">কোনো পরিচিত সার্ভিস পাওয়া যায়নি — এই প্রিন্টার সম্ভবত শুধু classic Bluetooth চালায় (BLE নয়), এই পথে সরাসরি প্রিন্ট করা যাবে না।</div>
                        </div>
                    </div>
                </template>

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
        <!-- Phase 41 — calibration test print overlay -->
        <Teleport to="body">
            <div v-if="calibrating" id="calib-overlay" style="position:fixed;inset:0;background:#fff;z-index:200;overflow-y:auto">
                <component :is="'style'" v-html="calibCss"></component>
                <div class="no-print" style="padding:12px;display:flex;gap:10px;align-items:center;background:var(--panel);border-bottom:1px solid var(--line)">
                    <button class="btn sm" style="width:auto" @click="printCalibration">🖨️ প্রিন্ট</button>
                    <button class="btn sm ghost" style="width:auto" @click="calibrating = false">বন্ধ</button>
                    <span style="font-size:12px;color:var(--mut)">প্রিন্ট ডায়ালগে: Scale 100%, Margins: None, Headers/footers বন্ধ, Paper size {{ calibSize.width }}×{{ calibSize.height }}</span>
                </div>
                <div class="no-print" style="padding:8px 12px;font-size:12px;color:var(--mut)">
                    ছাপার পর: চারপাশের বর্ডার লেবেলের কিনারা থেকে সমান দূরে আছে কিনা দেখুন — না থাকলে Offset X/Y বদলে আবার টেস্ট করুন। নিচের বারকোডটা ডাটাবেসের সবচেয়ে লম্বা মান — এটা স্ক্যান হলে সব লেবেলই হবে।
                </div>
                <div class="calib-card" :style="{ width: calibSize.width + 'mm', height: calibSize.height + 'mm', position: 'relative', margin: '12px', boxSizing: 'border-box', overflow: 'hidden' }">
                    <svg :viewBox="`0 0 ${calibSize.width} ${calibSize.height}`" :style="{ width: calibSize.width + 'mm', height: calibSize.height + 'mm', position: 'absolute', inset: 0 }">
                        <rect x="0.1" y="0.1" :width="calibSize.width - 0.2" :height="calibSize.height - 0.2" fill="none" stroke="#000" stroke-width="0.2" />
                        <line :x1="calibSize.width / 2 - 3" :y1="calibSize.height / 2" :x2="calibSize.width / 2 + 3" :y2="calibSize.height / 2" stroke="#000" stroke-width="0.15" />
                        <line :x1="calibSize.width / 2" :y1="calibSize.height / 2 - 3" :x2="calibSize.width / 2" :y2="calibSize.height / 2 + 3" stroke="#000" stroke-width="0.15" />
                        <line v-for="tk in calibTicks" :key="tk.x" :x1="tk.x" y1="0" :x2="tk.x" :y2="tk.len" stroke="#000" stroke-width="0.12" />
                    </svg>
                    <div style="position:absolute;left:0;right:0;bottom:1.5mm;text-align:center">
                        <svg id="calib-barcode-svg" style="display:inline-block"></svg>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.chk { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 14px; cursor: pointer; }
</style>
