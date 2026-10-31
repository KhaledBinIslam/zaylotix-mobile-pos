<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { encodeCost } from '@/support/costCode';

const props = defineProps({
    suppliers: Array, categories: Array, units: Array,
    colorCodeMap: Object, costCodeDigitMap: Object, kidsShoeSizeGroups: Array, kidsCategoryNames: Array,
});
const page = usePage();

// ---- draft autosave (localStorage, per-shop — a single in-progress form, not data worth a DB round-trip) ----
const draftKey = computed(() => `article-draft-${page.props.shop?.id ?? 'x'}`);

function uuidv4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

const form = useForm({
    client_uuid: uuidv4(),
    category_id: '', new_category_name: '', category_code: '',
    manufacturer_code: '',
    unit_id: '', new_unit_name: '',
    cost: '', price: '',
    kids_prices: { Small: '', Medium: '', Large: '' },
    colorsText: '', sizesText: '',
    variants: [], // [{color, size, qty}]
    photos: {}, // { COLOR: File } — optional, per SkyBuy-style picker (docs/ARTICLE_SERIES_PLAN.md §4)
});

try {
    const saved = JSON.parse(localStorage.getItem(draftKey.value) || 'null');
    if (saved) Object.assign(form, { ...saved, client_uuid: uuidv4() });
} catch (e) { /* corrupt/unavailable draft — start fresh, not fatal */ }

watch(form, (val) => {
    try {
        // client_uuid is regenerated per submit, not part of "what's on screen";
        // photos (File objects) can't be restored from a reload — a saved draft
        // just asks for the photo again, never silently drops the article
        const { client_uuid, photos, ...rest } = val;
        localStorage.setItem(draftKey.value, JSON.stringify(rest));
    } catch (e) { /* storage full/blocked — draft just won't survive a reload */ }
}, { deep: true });

// ---- category: existing (reuse its code) or new (type a name + code) ----
const categoryMode = computed(() => (form.category_id ? 'existing' : 'new'));
const selectedCategory = computed(() => props.categories.find((c) => c.id === form.category_id));
watch(() => form.category_id, (id) => {
    const cat = props.categories.find((c) => c.id === id);
    form.category_code = cat?.code || '';
});

const isKids = computed(() => {
    const name = categoryMode.value === 'existing' ? (selectedCategory.value?.name_en || selectedCategory.value?.name) : form.new_category_name;
    return props.kidsCategoryNames.includes((name || '').trim());
});

// ---- live "next serial" preview (debounced, read-only — never reserves it) ----
const previewSerial = ref(null);
let serialTimer = null;
watch([() => form.category_id, () => form.category_code], () => {
    clearTimeout(serialTimer);
    const categoryCode = (selectedCategory.value?.code || form.category_code || '').trim();
    if (!categoryCode) { previewSerial.value = null; return; }
    serialTimer = setTimeout(async () => {
        try {
            const params = new URLSearchParams(form.category_id ? { category_id: form.category_id } : { category_code: categoryCode });
            const res = await fetch(route('app.articles.peekSerial') + '?' + params, { headers: { Accept: 'application/json' } });
            previewSerial.value = (await res.json()).serial;
        } catch (e) { previewSerial.value = null; }
    }, 300);
}, { immediate: true });

// The cost's letter code, live. Null while the cost is empty OR while any
// of its digits still has no letter in this shop's map — in both cases the
// preview shows "?" rather than a half-built code, and the cost field's own
// error already explains the second case.
const previewCostCode = computed(() => encodeCost(props.costCodeDigitMap, form.cost));

const previewBarcode = computed(() => {
    const catCode = (selectedCategory.value?.code || form.category_code || '').trim();
    const mfr = form.manufacturer_code.trim().toUpperCase();
    if (!catCode || !mfr || !previewSerial.value) return null;

    return `${mfr}-${catCode}-${previewSerial.value}-${previewCostCode.value || '?'}`;
});

// docs/ARTICLE_SERIES_PLAN.md §9 — the color's own short code (shop setting,
// falling back to the color name itself uppercased — mirrors
// ArticleBarcode::colorCode() exactly) and the resulting sticker preview
function colorCode(color) {
    if (!color) return null;

    return props.colorCodeMap?.[color] ?? (/^[A-Za-z]+$/.test(color.replace(/\s/g, '')) ? color.toUpperCase() : color);
}
function previewColorBarcode(color) {
    if (!previewBarcode.value) return null;

    return `${previewBarcode.value}-${colorCode(color)}`;
}

// ---- color x size grid ----
function buildGrid() {
    const colors = form.colorsText.split(',').map((s) => s.trim().toUpperCase()).filter(Boolean);
    let sizes = [];
    const rangeMatch = form.sizesText.trim().match(/^(\d+)\s*-\s*(\d+)$/);
    if (rangeMatch) {
        const [from, to] = [parseInt(rangeMatch[1]), parseInt(rangeMatch[2])];
        for (let s = from; s <= to; s++) sizes.push(String(s));
    } else {
        sizes = form.sizesText.split(',').map((s) => s.trim()).filter(Boolean);
    }

    const existing = new Map(form.variants.map((v) => [`${v.color}|${v.size}`, v.qty]));
    const next = [];
    for (const color of colors.length ? colors : [null]) {
        for (const size of sizes) {
            next.push({ color, size, qty: existing.get(`${color}|${size}`) ?? 0 });
        }
    }
    form.variants = next;
}

const gridColors = computed(() => [...new Set(form.variants.map((v) => v.color))]);
const gridSizes = computed(() => [...new Set(form.variants.map((v) => v.size))]);
function cellFor(color, size) {
    return form.variants.find((v) => v.color === color && v.size === size);
}
const totalStock = computed(() => form.variants.reduce((s, v) => s + (Number(v.qty) || 0), 0));

// ---- submit + "একবার এন্ট্রি, সব জায়গায়" (next article remembers manufacturer/category/colors/sizes) ----
const justSaved = ref(null); // { barcode } while the success banner shows

function onColorPhotoChange(color, event) {
    form.photos[color] = event.target.files?.[0] || null;
}
const photoPreviewUrls = ref({}); // color -> object URL, for this session's picked files only
watch(() => ({ ...form.photos }), (photos, oldPhotos) => {
    for (const color of Object.keys(photos)) {
        if (photos[color] && photos[color] !== oldPhotos?.[color]) {
            photoPreviewUrls.value[color] = URL.createObjectURL(photos[color]);
        }
    }
});

function save() {
    justSaved.value = null;
    form.transform((data) => ({
        ...data,
        category_id: data.category_id || null,
        price: isKids.value ? null : data.price,
        kids_prices: isKids.value ? data.kids_prices : null,
    })).post(route('app.articles.store'), {
        forceFormData: true,
        onSuccess: () => {
            justSaved.value = { barcode: page.props.flash?.productBarcode };
            // carries manufacturer + category + colors/sizes into the next entry; clears everything per-article
            form.reset('cost', 'price', 'variants', 'photos');
            form.kids_prices = { Small: '', Medium: '', Large: '' };
            photoPreviewUrls.value = {};
            form.client_uuid = uuidv4();
            buildGrid(); // re-apply the same color/size shape with qty back to 0
        },
    });
}
</script>

<template>
    <Head title="নতুন আর্টিকেল" />
    <AppLayout active="stock">
        <div class="pgttl">নতুন আর্টিকেল</div>
        <div class="pgsub">ম্যানুফ্যাকচার, ক্যাটাগরি, কস্ট আর রং×সাইজ গ্রিড — সব একই স্ক্রিনে</div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <Link :href="route('app.stock')" class="btn ghost sm" style="text-decoration:none">← স্টকে ফিরুন</Link>
            <div style="display:flex;gap:8px">
                <Link :href="route('app.articles.colorCodeSettings.edit')" class="btn ghost sm" style="text-decoration:none">⚙️ রং-কোড সেটিংস</Link>
                <Link :href="route('app.articles.sizeGroupSettings.edit')" class="btn ghost sm" style="text-decoration:none">⚙️ সাইজ-গ্রুপ সেটিংস</Link>
                <Link :href="route('app.articles.costCodeSettings.edit')" class="btn ghost sm" style="text-decoration:none">⚙️ কস্ট-কোড সেটিংস</Link>
            </div>
        </div>

        <div v-if="justSaved" class="card" style="background:var(--green-bg,#eafaf0);border-color:var(--green,#1aa260);margin-bottom:16px">
            ✅ আর্টিকেল যোগ হয়েছে। বারকোড: <strong>{{ justSaved.barcode }}</strong> — উপরের ম্যানুফ্যাকচার/ক্যাটাগরি/রং/সাইজ রেখে দেওয়া হয়েছে, নিচে নতুন কস্ট-দাম দিয়ে পরের আর্টিকেল যোগ করুন।
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="field">
                <label>ম্যানুফ্যাকচার কোড (যেমন SMS)</label>
                <input v-model="form.manufacturer_code" list="mfr-suggestions" placeholder="SMS">
                <datalist id="mfr-suggestions">
                    <option v-for="s in suppliers" :key="s.id" :value="s.short_code">{{ s.name }}</option>
                </datalist>
                <div v-if="form.errors.manufacturer_code" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.manufacturer_code }}</div>
            </div>

            <div class="f2">
                <div class="field">
                    <label>ক্যাটাগরি</label>
                    <select v-model="form.category_id">
                        <option value="">+ নতুন ক্যাটাগরি</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} {{ c.code ? `(${c.code})` : '' }}</option>
                    </select>
                </div>
                <div class="field" v-if="categoryMode === 'new'">
                    <label>নতুন ক্যাটাগরির নাম</label>
                    <input v-model="form.new_category_name" placeholder="যেমন বাচ্চাদের জুতা">
                    <div v-if="form.errors.new_category_name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.new_category_name }}</div>
                </div>
            </div>

            <div class="field" v-if="categoryMode === 'new' || !selectedCategory?.code">
                <label>ক্যাটাগরি কোড (বারকোডে ব্যবহার হবে, যেমন L)</label>
                <input v-model="form.category_code" placeholder="L" maxlength="5" style="text-transform:uppercase">
                <div v-if="form.errors.category_code" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.category_code }}</div>
            </div>

            <div class="field" v-if="previewBarcode">
                <label>বারকোড প্রিভিউ (cost দিলে পূর্ণ হবে)</label>
                <div style="font-family:monospace;font-size:15px;font-weight:700">{{ previewBarcode }}</div>
            </div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="f2">
                <div class="field">
                    <label>ইউনিট</label>
                    <select v-model="form.unit_id">
                        <option value="">+ নতুন ইউনিট</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </div>
                <div class="field" v-if="!form.unit_id">
                    <label>নতুন ইউনিটের নাম</label>
                    <input v-model="form.new_unit_name" placeholder="জোড়া">
                </div>
            </div>

            <div class="field">
                <label>কস্ট (ক্রয় মূল্য)</label>
                <input v-model.number="form.cost" type="number" min="0" placeholder="1300">
                <div v-if="form.errors.cost" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.cost }}</div>
            </div>

            <template v-if="isKids">
                <div class="field"><label>বাচ্চাদের জুতা — সাইজ অনুযায়ী ৩টা দাম</label></div>
                <div class="f2" style="grid-template-columns:1fr 1fr 1fr">
                    <div class="field" v-for="g in kidsShoeSizeGroups" :key="g.label">
                        <label>{{ g.label }} ({{ g.min_size }}–{{ g.max_size }})</label>
                        <input v-model.number="form.kids_prices[g.label]" type="number" min="0" placeholder="0">
                    </div>
                </div>
                <div v-if="form.errors.kids_prices" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.kids_prices }}</div>
            </template>
            <template v-else>
                <div class="field">
                    <label>বিক্রয় মূল্য</label>
                    <input v-model.number="form.price" type="number" min="0" placeholder="1990">
                    <div v-if="form.errors.price" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.price }}</div>
                </div>
            </template>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="f2">
                <div class="field">
                    <label>রং (কমা দিয়ে আলাদা করুন)</label>
                    <input v-model="form.colorsText" placeholder="SILVER, KHAKI, BLACK">
                </div>
                <div class="field">
                    <label>সাইজ (রেঞ্জ যেমন 36-40, বা কমা দিয়ে)</label>
                    <input v-model="form.sizesText" placeholder="36-40">
                </div>
            </div>
            <button type="button" class="btn ghost sm" @click="buildGrid">গ্রিড তৈরি করুন</button>

            <div v-if="gridSizes.length" style="overflow-x:auto;margin-top:12px">
                <table style="border-collapse:collapse;width:100%">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:4px 8px;font-size:12px;color:var(--mut)">রং \ সাইজ</th>
                            <th v-for="size in gridSizes" :key="size" style="padding:4px 8px;font-size:12px;color:var(--mut)">{{ size }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="color in gridColors" :key="color ?? '_'">
                            <td style="padding:4px 8px">
                                <div style="display:flex;align-items:center;gap:6px">
                                    <img v-if="photoPreviewUrls[color]" :src="photoPreviewUrls[color]" style="width:28px;height:28px;border-radius:6px;object-fit:cover;flex-shrink:0">
                                    <div v-else style="width:28px;height:28px;border-radius:6px;background:var(--panel2);flex-shrink:0"></div>
                                    <b>{{ color ?? '(এক রং)' }}</b>
                                </div>
                                <label v-if="color" style="font-size:11px;color:var(--sky);cursor:pointer;display:inline-block;margin-top:3px">
                                    {{ photoPreviewUrls[color] ? 'ছবি বদলান' : '📷 ছবি (ঐচ্ছিক)' }}
                                    <input type="file" accept="image/*" style="display:none" @change="onColorPhotoChange(color, $event)">
                                </label>
                                <div v-if="color && previewColorBarcode(color)" style="font-family:monospace;font-size:10px;color:var(--mut);margin-top:3px">{{ previewColorBarcode(color) }}</div>
                            </td>
                            <td v-for="size in gridSizes" :key="size" style="padding:4px 4px">
                                <input v-model.number="cellFor(color, size).qty" type="number" min="0" style="width:64px;text-align:center">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top:8px;font-size:13px;color:var(--mut)">মোট স্টক: <strong>{{ totalStock }}</strong></div>
            </div>
            <div v-if="form.errors.variants" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.variants }}</div>
        </div>

        <button type="button" class="btn primary" style="width:100%" :disabled="form.processing" @click="save">
            {{ form.processing ? 'যোগ হচ্ছে...' : 'আর্টিকেল যোগ করুন' }}
        </button>

        <div class="card" style="margin-top:20px;font-size:13px;color:var(--mut);line-height:1.6">
            <strong style="color:var(--fg)">কীভাবে ব্যবহার করবেন:</strong><br>
            ১. ম্যানুফ্যাকচার কোড আর ক্যাটাগরি বেছে নিন (নতুন হলে নাম+কোড দিন)।<br>
            ২. কস্ট দিন — বারকোড নিজেই তৈরি হয়ে যাবে।<br>
            ৩. বাচ্চাদের জুতা হলে Small/Medium/Large ৩টা দাম দিন, নয়তো একটা বিক্রয় মূল্য দিন।<br>
            ৪. রং আর সাইজ রেঞ্জ লিখে "গ্রিড তৈরি করুন" চাপুন, তারপর প্রতিটা ঘরে স্টক সংখ্যা দিন — চাইলে প্রতিটা রঙে একটা ছবিও দিতে পারেন (ঐচ্ছিক), POS আর Stock-এ রঙের থাম্বনেইল হিসেবে দেখাবে।<br>
            ৫. "আর্টিকেল যোগ করুন" চাপুন — ম্যানুফ্যাকচার/ক্যাটাগরি/রং/সাইজ পরের আর্টিকেলের জন্য থেকে যাবে, শুধু কস্ট-দাম-স্টক নতুন করে দিন।<br>
            লেখা অসম্পূর্ণ অবস্থায় পেজ বন্ধ হয়ে গেলেও এটা মনে রাখা হয়, ফিরে এলে আগের জায়গা থেকেই শুরু করতে পারবেন।
        </div>
    </AppLayout>
</template>
