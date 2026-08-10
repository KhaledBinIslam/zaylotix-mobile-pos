<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useI18n } from '@/composables/useI18n';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';

const props = defineProps({
    products: Object, categories: Array, units: Array, stats: Object, q: String, categoryId: [Number, String],
    company: String, genericName: String, companies: { type: Array, default: () => [] }, genericNames: { type: Array, default: () => [] },
});

const page = usePage();
const features = computed(() => page.props.features || []);
const hasUnitConversion = computed(() => features.value.includes('unit_conversion'));
const hasLowStockAlerts = computed(() => features.value.includes('low_stock_alerts'));
const hasBatchTracking = computed(() => features.value.includes('batch_tracking'));
const hasProductVariants = computed(() => features.value.includes('product_variants'));
const hasSerialTracking = computed(() => features.value.includes('serial_tracking'));
const hasPrescriptionRecords = computed(() => features.value.includes('prescription_records'));
const hasWeightBasedSelling = computed(() => features.value.includes('weight_based_selling'));
const hasWholesalePricing = computed(() => features.value.includes('wholesale_pricing'));
// gates the stock-mode picker below — a cooked dish's "stock" doesn't mean
// a count the way it does for every other vertical (see Product::STOCK_MODE_*
// and TableOrderController), so only a restaurant shop's form offers it;
// every other business type's stock field stays exactly as it always was
const isRestaurant = computed(() => features.value.includes('restaurant_tables'));
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// search/category filter now run server-side (see ProductController::index)
// — necessary once the list is paginated, since a client-side filter can
// only ever see whatever page happened to load, not the whole catalog
const q = ref(props.q || '');
const searchInput = ref(null);
const cat = ref(props.categoryId || 'all');
const companyFilter = ref(props.company || '');
const genericFilter = ref(props.genericName || '');
const filtered = computed(() => props.products.data);
function applyFilter() {
    router.get(route('app.stock'), {
        q: q.value || undefined,
        category_id: cat.value === 'all' ? undefined : cat.value,
        company: companyFilter.value || undefined,
        generic_name: genericFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}
function setCategory(id) {
    cat.value = id;
    applyFilter();
}

// a weighed product's own "low" threshold is 1 kg/litre, not 6 pieces —
// 6 loose grams left on the shelf would be meaningless as a low-stock signal
function lowThreshold(p) {
    return p.sold_by_weight ? 1 : 6;
}
// computed server-side against the WHOLE catalog (see ProductController) —
// these are dashboard totals, not a summary of whichever page is showing
const totalProducts = computed(() => props.stats.total);
const lowStockCount = computed(() => props.stats.low_stock);
const outOfStockCount = computed(() => props.stats.out_of_stock);
const expiringSoonCount = computed(() => props.stats.expiring_soon);
const categoryCounts = computed(() => props.stats.category_counts);

function unitLabel(p) {
    if (!p.sold_by_weight) return t('stock.pieces');
    return p.weight_unit === 'litre' ? t('stock.unitLitre') : t('stock.unitKg');
}
// weighed stock shows up to 3 decimals (never trailing zeros past what's
// needed) so 0.25 kg reads as "0.25 kg", not "0.250 kg" or rounded to "0 kg"
function formatQty(p, val) {
    const n = Number(val);
    return p.sold_by_weight ? (Math.round(n * 1000) / 1000) : Math.round(n);
}
function badge(p) {
    if (p.stock_mode === 'untracked') return { cls: 'mut', text: t('pos.alwaysAvailable') };
    if (p.stock_mode === 'toggle') {
        return Number(p.stock) > 0
            ? { cls: 'mut', text: t('pos.availableToday') }
            : { cls: 'rose', text: t('pos.soldOutToday') };
    }
    const stock = Number(p.stock);
    if (stock <= 0) return { cls: 'rose', text: t('stock.outOfStock') };
    if (stock <= lowThreshold(p)) return { cls: 'gold', text: `${t('stock.lowStock')} ${formatQty(p, stock)} ${unitLabel(p)}` };
    return { cls: 'mut', text: `${formatQty(p, stock)} ${unitLabel(p)}` };
}

// --- add / edit product sheet ---
const productSheet = ref(false);
const editing = ref(null);
// a variant product's stock is a live-maintained sum of its variants —
// the generic stock field/stock-in flow is hidden for these so nothing
// can write a number that drifts away from that sum (see variant
// management section below instead)
const editingHasVariants = computed(() => (editing.value?.variants?.length ?? 0) > 0);
const form = useForm({
    name: '', name_en: '', generic_name: '', company: '', shelf_location: '', requires_prescription: false, emoji: '📦', photo: null, remove_photo: false, category_id: '', new_category_name: '',
    unit_id: '', new_unit_name: '', barcode: '', cost: '', price: '', wholesale_price: '', discount_price: '', stock: '',
    reorder_point: '', sold_by_weight: false, weight_unit: 'kg',
    // restaurant-only (see isRestaurant) — 'tracked' everywhere else,
    // unchanged from before this field existed
    stock_mode: 'tracked', available: true,
});

// live preview of a newly-picked photo, before it's actually uploaded —
// falls back to the product's already-saved photo (when editing) or nothing
const newPhotoPreview = ref(null);
function pickPhoto(e) {
    const file = e.target.files[0];
    form.photo = file || null;
    form.remove_photo = false;
    newPhotoPreview.value = file ? URL.createObjectURL(file) : null;
}
function removePhoto() {
    form.photo = null;
    form.remove_photo = true;
    newPhotoPreview.value = null;
}

// --- medicine catalog search (pharmacy) — a lookup helper only, never a
// source of price/stock; picking a result just pre-fills name/generic/company ---
const medicineQuery = ref('');
const medicineResults = ref([]);
let medicineSearchTimer = null;
function searchMedicineCatalog() {
    clearTimeout(medicineSearchTimer);
    medicineSearchTimer = setTimeout(async () => {
        if (medicineQuery.value.trim().length < 2) { medicineResults.value = []; return; }
        const res = await fetch(route('app.medicineCatalog.search') + '?q=' + encodeURIComponent(medicineQuery.value), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        medicineResults.value = data.results;
    }, 300);
}
function pickMedicine(m) {
    form.name = m.name;
    form.generic_name = m.generic_name;
    form.company = m.company;
    medicineQuery.value = '';
    medicineResults.value = [];
}

function openNew() {
    editing.value = null;
    form.reset();
    // 'untracked' (always available) is the sane restaurant default — a
    // cooked dish with a blank/zero stock used to make every "add to
    // order" click silently fail (see Order.vue's addItem() and
    // DemoShopSeeder's restaurantDemo()); every other vertical keeps the
    // 'tracked' default form.reset() already restores above
    form.stock_mode = isRestaurant.value ? 'untracked' : 'tracked';
    newPhotoPreview.value = null;
    medicineQuery.value = '';
    medicineResults.value = [];
    productSheet.value = true;
}
function openEdit(p) {
    editing.value = p;
    form.name = p.name; form.name_en = p.name_en; form.emoji = p.emoji;
    form.generic_name = p.generic_name; form.company = p.company; form.shelf_location = p.shelf_location; form.requires_prescription = p.requires_prescription;
    form.category_id = p.category_id; form.unit_id = p.unit_id; form.barcode = p.barcode;
    form.cost = p.cost; form.price = p.price; form.wholesale_price = p.wholesale_price; form.discount_price = p.discount_price; form.stock = p.stock;
    form.reorder_point = p.reorder_point;
    form.stock_mode = p.stock_mode || 'tracked';
    form.available = Number(p.stock) > 0; // only meaningful when stock_mode is 'toggle' — see Product::isMarkedAvailable()
    form.sold_by_weight = !!p.sold_by_weight; form.weight_unit = p.weight_unit || 'kg';
    form.photo = null; form.remove_photo = false;
    newPhotoPreview.value = null;
    form.new_category_name = ''; form.new_unit_name = '';
    productSheet.value = true;
}

/** The quick pill on the product list — only ever shown for a 'toggle'-mode product, no need to open the edit sheet just to flip it. */
function toggleAvailability(p) {
    router.patch(route('app.products.availability', p.id), { available: !(Number(p.stock) > 0) }, { preserveScroll: true });
}

// --- pack sizes (box/strip/...) — only relevant with unit_conversion feature ---
const packForm = useForm({ unit_id: '', new_unit_name: '', factor: '', price: '' });
// live "so this means..." readout while typing factor/price — the numbers
// alone (a bare "10" in a "how many pieces" box) don't make it obvious this
// is defining a whole second sellable option at POS, priced independently
// of the per-piece price above; this spells out both directions so a shop
// owner setting up e.g. Sergel (1 box = 10 strips = 100 tablets) can see
// they got the factor right before saving, not after a wrong sale.
const packSizePreview = computed(() => {
    const factor = Number(packForm.factor);
    const price = Number(packForm.price);
    if (!factor || factor < 2 || !price) return null;
    return { perPiece: price / factor };
});

// "1 box = how many pieces" is what actually gets stored (ProductUnit.factor
// is always base-pieces, never nested — see PosController), but a pharmacy
// owner naturally thinks "1 box = 10 strips", not "= 100 tablets", and would
// otherwise have to do that multiplication in their head before typing a
// number in. This lets them pick an already-added pack size (e.g. স্ট্রিপ)
// and say how many of THAT make up the new one — the piece count still
// lands in packForm.factor exactly as before (still visible, still
// editable), just computed for them instead of by them.
const packBasedOnId = ref('');
const packBasedOnCount = ref('');
const packBasedOnUnit = computed(() => editing.value?.product_units?.find((pu) => pu.id === Number(packBasedOnId.value)) || null);
watch([packBasedOnId, packBasedOnCount], () => {
    if (packBasedOnUnit.value && Number(packBasedOnCount.value) > 0) {
        packForm.factor = Number(packBasedOnCount.value) * packBasedOnUnit.value.factor;
    }
});
function addPackSize() {
    packForm.post(route('app.productUnits.store', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            packForm.reset();
            packBasedOnId.value = '';
            packBasedOnCount.value = '';
        },
    });
}
function removePackSize(pu) {
    if (!confirm(`"${pu.unit?.name}" ${t('stock.removePackConfirm')}`)) return;
    router.delete(route('app.productUnits.destroy', pu.id), { preserveScroll: true });
}

// --- variants (size/color) — only relevant with product_variants feature ---
const variantForm = useForm({ size: '', color: '', barcode: '', stock: '', reorder_point: '', price: '', cost: '' });
function addVariant() {
    variantForm.post(route('app.productVariants.store', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => variantForm.reset(),
    });
}
function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}

// --- grid entry: type a color list + a size list once, fill a color x size
// matrix in one go instead of clicking "add variant" per combination ---
const gridColorsInput = ref('');
const gridSizesInput = ref('');
const gridOpen = ref(false);
const gridStock = ref({}); // { "color|size": qty }
const gridColors = computed(() => gridColorsInput.value.split(',').map((s) => s.trim()).filter(Boolean));
const gridSizes = computed(() => gridSizesInput.value.split(',').map((s) => s.trim()).filter(Boolean));
const gridProcessing = ref(false);
const gridError = ref('');

function gridCellKey(color, size) {
    return `${color}|${size}`;
}
function openGrid() {
    gridOpen.value = true;
    gridStock.value = {};
    gridError.value = '';
}
function submitGrid() {
    const colors = gridColors.value.length ? gridColors.value : [''];
    const sizes = gridSizes.value.length ? gridSizes.value : [''];
    if (colors.length === 1 && colors[0] === '' && sizes.length === 1 && sizes[0] === '') {
        gridError.value = t('stock.gridNeedsColorOrSize');
        return;
    }

    const variants = [];
    for (const color of colors) {
        for (const size of sizes) {
            const qty = gridStock.value[gridCellKey(color, size)];
            if (qty === undefined || qty === null || qty === '') continue;
            variants.push({ color: color || null, size: size || null, stock: Number(qty) });
        }
    }
    if (!variants.length) {
        gridError.value = t('stock.gridNeedsAtLeastOneCell');
        return;
    }

    gridProcessing.value = true;
    router.post(route('app.productVariants.bulkStore', editing.value.id), { variants }, {
        preserveScroll: true,
        onSuccess: () => {
            gridOpen.value = false;
            gridColorsInput.value = '';
            gridSizesInput.value = '';
            gridStock.value = {};
        },
        onError: (errors) => { gridError.value = errors.size || ''; },
        onFinish: () => { gridProcessing.value = false; },
    });
}
function removeVariant(v) {
    if (!confirm(`"${variantLabel(v)}" ${t('stock.removeVariantConfirm')}`)) return;
    router.delete(route('app.productVariants.destroy', v.id), { preserveScroll: true });
}
const variantStockInQty = ref({}); // { [variantId]: qty }
function stockInVariant(v) {
    const qty = variantStockInQty.value[v.id];
    if (!qty || qty <= 0) return;
    router.post(route('app.productVariants.stockIn', v.id), { qty }, {
        preserveScroll: true,
        onSuccess: () => (variantStockInQty.value[v.id] = ''),
    });
}
function saveProduct() {
    if (editing.value) {
        form.put(route('app.products.update', editing.value.id), { onSuccess: () => (productSheet.value = false) });
    } else {
        form.post(route('app.products.store'), { onSuccess: () => (productSheet.value = false) });
    }
}

function deleteProduct() {
    if (!editing.value) return;
    if (!confirm(`"${editing.value.name}" ${t('stock.deleteConfirm')}`)) return;
    router.delete(route('app.products.destroy', editing.value.id), {
        onSuccess: () => (productSheet.value = false),
    });
}

// --- stock-in sheet ---
const stockInSheet = ref(false);
const stockInProduct = ref(null);
const stockInQty = ref('');
const stockInCost = ref('');
const stockInBatchNo = ref('');
const stockInExpiryDate = ref('');
const stockInImeis = ref('');
const stockInWarrantyExpiry = ref('');
const stockInSubmitting = ref(false);
function openStockIn(p) {
    stockInProduct.value = p;
    stockInQty.value = '';
    stockInCost.value = '';
    stockInBatchNo.value = '';
    stockInExpiryDate.value = '';
    stockInImeis.value = '';
    stockInWarrantyExpiry.value = '';
    stockInSheet.value = true;
}
function applyStockIn() {
    if (!stockInQty.value || stockInQty.value <= 0) return;
    stockInSubmitting.value = true;
    router.post(route('app.products.stockIn', stockInProduct.value.id), {
        qty: stockInQty.value, cost: stockInCost.value,
        batch_no: hasBatchTracking.value ? stockInBatchNo.value : '',
        expiry_date: hasBatchTracking.value ? stockInExpiryDate.value : '',
        imeis: hasSerialTracking.value ? stockInImeis.value : '',
        warranty_expiry: hasSerialTracking.value ? stockInWarrantyExpiry.value : '',
    }, {
        onSuccess: () => (stockInSheet.value = false),
        onFinish: () => (stockInSubmitting.value = false),
    });
}

// --- CSV import sheet ---
const importSheet = ref(false);
const importForm = useForm({ file: null });
function saveImport() {
    importForm.post(route('app.products.import.store'), {
        onSuccess: () => { importSheet.value = false; importForm.reset(); },
    });
}

// same reasoning as the POS page — another device (cashier, or the owner
// on a second phone) can change stock at any moment, so quietly re-check
// every 15s rather than showing a number that's gone stale
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (productSheet.value || stockInSheet.value) return;
        router.reload({ only: ['products'], preserveScroll: true, preserveState: true });
    }, 15000);
});
onBeforeUnmount(() => clearInterval(pollTimer));

useKeyboardShortcuts({
    F2: () => searchInput.value?.focus(),
    F9: () => openNew(),
    Escape: () => { if (productSheet.value) productSheet.value = false; },
});
</script>

<template>
    <Head :title="isRestaurant ? t('nav.stockRestaurant') : t('nav.stock')" />
    <AppLayout active="stock">
        <div class="pgttl">{{ isRestaurant ? t('nav.stockFullRestaurant') : t('nav.stockFull') }}</div>
        <div class="pgsub">{{ isRestaurant ? t('stock.subtitleRestaurant') : t('stock.subtitle') }} {{ totalProducts }} {{ isRestaurant ? t('stock.itemsCountWordRestaurant') : t('home.products') }}</div>
        <HowToHint screen-key="stock" />
        <div class="hidden lg:block" style="font-size:11.5px;color:var(--dim);margin-bottom:10px">{{ isRestaurant ? t('stock.shortcutsHintRestaurant') : t('stock.shortcutsHint') }}</div>

        <div class="grid2" style="margin-bottom:14px">
            <div class="stat sky"><div class="k">{{ isRestaurant ? t('stock.totalProductsRestaurant') : t('stock.totalProducts') }}</div><div class="v">{{ totalProducts }}</div></div>
            <div class="stat gold"><div class="k">{{ t('stock.lowStockCount') }}</div><div class="v">{{ lowStockCount }}</div></div>
            <div class="stat rose"><div class="k">{{ t('stock.outOfStockCount') }}</div><div class="v">{{ outOfStockCount }}</div></div>
            <div v-if="hasBatchTracking" class="stat mint"><div class="k">{{ t('stock.expiringSoonCount') }}</div><div class="v">{{ expiringSoonCount }}</div></div>
        </div>

        <div class="btnrow" style="margin-bottom:12px">
            <button class="btn ghost" style="flex:1" @click="openNew">{{ isRestaurant ? t('stock.addProductRestaurant') : t('stock.addProduct') }}</button>
            <button class="btn ghost" style="flex:1" @click="importSheet = true">{{ t('stock.importCsv') }}</button>
        </div>

        <!-- was previously only reachable as a side-effect of editing a
             specific product's unit dropdown ("or type a new unit") — a
             direct, standalone link here too, right where a shop owner is
             already thinking about their inventory setup -->
        <Link :href="route('app.units.index')" class="row" style="margin-bottom:12px">
            <div class="ava">📏</div><div class="mid"><b>{{ t('unit.title') }}</b><span>{{ t('unit.subtitle') }}</span></div><div class="end">›</div>
        </Link>

        <div style="display:flex;gap:8px;margin-bottom:12px">
            <input ref="searchInput" v-model="q" :placeholder="isRestaurant ? t('stock.searchPlaceholderRestaurant') : t('stock.searchPlaceholder')" style="flex:1" @keyup.enter="applyFilter">
            <button class="btn sm" style="width:auto;padding:0 16px" @click="applyFilter">{{ t('sales.searchButton') }}</button>
        </div>

        <div v-if="hasBatchTracking && (companies.length || genericNames.length)" class="f2" style="margin-bottom:12px">
            <div v-if="companies.length" class="field" style="margin-bottom:0">
                <select v-model="companyFilter" @change="applyFilter">
                    <option value="">{{ t('stock.allCompanies') }}</option>
                    <option v-for="c in companies" :key="c" :value="c">{{ c }}</option>
                </select>
            </div>
            <div v-if="genericNames.length" class="field" style="margin-bottom:0">
                <select v-model="genericFilter" @change="applyFilter">
                    <option value="">{{ t('stock.allGenerics') }}</option>
                    <option v-for="g in genericNames" :key="g" :value="g">{{ g }}</option>
                </select>
            </div>
        </div>

        <div class="tabbar">
            <button :class="{ on: cat === 'all' }" @click="setCategory('all')">{{ t('stock.allCategories') }} · {{ totalProducts }}</button>
            <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="setCategory(c.id)">{{ c.emoji }} {{ c.name }} · {{ categoryCounts[c.id] || 0 }}</button>
        </div>

        <div v-for="p in filtered" :key="p.id" class="row" @click="openEdit(p)">
            <div class="ava" style="overflow:hidden;padding:0">
                <img v-if="p.photo_url" :src="p.photo_url" style="width:100%;height:100%;object-fit:cover" :alt="p.name">
                <template v-else>{{ p.emoji }}</template>
            </div>
            <div class="mid">
                <b>{{ p.name }} <span v-if="p.requires_prescription" title="Rx" style="color:var(--rose)">℞</span></b>
                <span>{{ p.category?.emoji }} {{ p.category?.name }} • {{ money(p.price) }}{{ p.sold_by_weight ? '/' + unitLabel(p) : '' }}</span>
                <span v-if="p.generic_name || p.company" style="color:var(--mut)">💊 {{ p.generic_name }}<template v-if="p.company"> • 🏭 {{ p.company }}</template><template v-if="p.shelf_location"> • 📍 {{ p.shelf_location }}</template></span>
                <!-- a batch can be tracked by batch_no alone with no expiry_date at
                     all (see ProductBatch::receive's own comment) — nearest_batch can
                     legitimately be non-null with a null expiry_date in that case, so
                     this checks the date specifically, not just the batch's presence -->
                <span v-if="p.nearest_batch?.expiry_date" style="color:var(--rose);font-weight:600">⏳ {{ t('stock.expiresOn') }} {{ p.nearest_batch.expiry_date.slice(0, 10) }}</span>
            </div>
            <div class="end">
                <span class="pill" :class="badge(p).cls">{{ badge(p).text }}</span>
                <button v-if="p.stock_mode === 'toggle'" class="btn sm ghost" style="margin-top:6px" @click.stop="toggleAvailability(p)">
                    {{ Number(p.stock) > 0 ? t('stock.markSoldOut') : t('stock.markAvailable') }}
                </button>
                <button v-else-if="!p.variants?.length && p.stock_mode !== 'untracked'" class="btn sm ghost" style="margin-top:6px" @click.stop="openStockIn(p)">{{ t('stock.stockIn') }}</button>
            </div>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">📦</div>{{ isRestaurant ? t('stock.noProductsRestaurant') : t('stock.noProducts') }}</div>
        <Pagination :links="products.links" />

        <Sheet v-model="productSheet" :title="editing ? (isRestaurant ? t('stock.editTitleRestaurant') : t('stock.editTitle')) : (isRestaurant ? t('stock.newTitleRestaurant') : t('stock.newTitle'))">
            <div v-if="!editing && hasBatchTracking" class="field">
                <label>{{ t('stock.medicineCatalogSearch') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="medicineQuery" :placeholder="t('stock.medicineCatalogPlaceholder')" @input="searchMedicineCatalog">
                <div v-if="medicineResults.length" class="card" style="margin-top:6px;padding:0">
                    <button
                        v-for="m in medicineResults" :key="m.id" type="button" class="row" style="width:100%;text-align:left;box-shadow:none"
                        @click="pickMedicine(m)"
                    >
                        <div class="mid"><b>{{ m.name }}</b><span>{{ m.generic_name }} • {{ m.company }}</span></div>
                    </button>
                </div>
            </div>
            <div class="field">
                <label>{{ isRestaurant ? t('stock.productNameRestaurant') : t('stock.productName') }}</label>
                <input v-model="form.name">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>
            <div v-if="hasBatchTracking" class="field">
                <label>{{ t('stock.genericName') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.genericNameHint') }}</span></label>
                <input v-model="form.generic_name" :placeholder="t('stock.genericNamePlaceholder')" list="generic-name-options">
                <datalist id="generic-name-options"><option v-for="g in genericNames" :key="g" :value="g" /></datalist>
                <div v-if="form.errors.generic_name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.generic_name }}</div>
            </div>
            <div v-if="hasBatchTracking" class="field">
                <label>{{ t('stock.company') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.company" :placeholder="t('stock.companyPlaceholder')" list="company-options">
                <datalist id="company-options"><option v-for="c in companies" :key="c" :value="c" /></datalist>
                <div v-if="form.errors.company" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.company }}</div>
            </div>
            <div v-if="hasBatchTracking" class="field">
                <label>{{ t('stock.shelfLocation') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.shelf_location" :placeholder="t('stock.shelfLocationPlaceholder')">
                <div v-if="form.errors.shelf_location" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.shelf_location }}</div>
            </div>
            <label v-if="hasPrescriptionRecords" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:13.5px;font-weight:600;cursor:pointer">
                <input v-model="form.requires_prescription" type="checkbox" style="width:auto">
                {{ t('stock.requiresPrescription') }}
            </label>
            <div class="field">
                <label>{{ isRestaurant ? t('stock.photoRestaurant') : t('stock.photo') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.photoHint') }}</span></label>
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="ava" style="width:56px;height:56px;overflow:hidden;padding:0;flex:0 0 auto">
                        <img v-if="newPhotoPreview || (editing?.photo_url && !form.remove_photo)" :src="newPhotoPreview || editing.photo_url" style="width:100%;height:100%;object-fit:cover">
                        <template v-else>{{ form.emoji }}</template>
                    </div>
                    <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                        <input type="file" accept="image/*" @change="pickPhoto">
                        <button v-if="newPhotoPreview || (editing?.photo_url && !form.remove_photo)" class="btn sm ghost" type="button" @click="removePhoto">{{ t('stock.removePhoto') }}</button>
                    </div>
                </div>
                <div v-if="form.errors.photo" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.photo }}</div>
            </div>
            <div class="field">
                <label>{{ t('stock.category') }}</label>
                <select v-model="form.category_id">
                    <option :value="''">{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.emoji }} {{ c.name }}</option>
                </select>
                <div v-if="form.errors.category_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.category_id }}</div>
            </div>
            <div class="field"><label>{{ t('stock.orNewCategory') }}</label><input v-model="form.new_category_name" :placeholder="t('stock.orNewCategoryPlaceholder')"></div>
            <div class="field">
                <label>{{ t('stock.unit') }}</label>
                <select v-model="form.unit_id">
                    <option :value="''">{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <div v-if="form.errors.unit_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.unit_id }}</div>
            </div>
            <div class="field"><label>{{ t('stock.orNewUnit') }}</label><input v-model="form.new_unit_name" :placeholder="t('stock.orNewUnitPlaceholder')"></div>

            <div v-if="hasWeightBasedSelling && !editingHasVariants" class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="form.sold_by_weight" type="checkbox" style="width:auto">
                    {{ t('stock.soldByWeight') }}
                </label>
                <div style="color:var(--dim);font-size:12px;margin-top:4px">{{ t('stock.soldByWeightHint') }}</div>
                <div v-if="form.sold_by_weight" class="seg" style="margin-top:8px">
                    <button type="button" :class="{ on: form.weight_unit === 'kg' }" @click="form.weight_unit = 'kg'">{{ t('stock.unitKg') }}</button>
                    <button type="button" :class="{ on: form.weight_unit === 'litre' }" @click="form.weight_unit = 'litre'">{{ t('stock.unitLitre') }}</button>
                </div>
            </div>

            <div class="f2">
                <div class="field">
                    <label>{{ form.sold_by_weight ? t('stock.sellPricePerUnit', { unit: form.weight_unit === 'litre' ? t('stock.unitLitre') : t('stock.unitKg') }) : t('stock.sellPrice') }}</label>
                    <input v-model="form.price" type="number" step="0.01">
                    <div v-if="form.errors.price" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.price }}</div>
                </div>
                <div class="field">
                    <label>{{ t('stock.costPrice') }}</label>
                    <input v-model="form.cost" type="number" step="0.01">
                    <div v-if="form.errors.cost" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.cost }}</div>
                </div>
            </div>
            <div v-if="hasWholesalePricing && !form.sold_by_weight" class="field">
                <label>{{ t('stock.wholesalePrice') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.wholesalePriceHint') }}</span></label>
                <input v-model="form.wholesale_price" type="number" step="0.01">
                <div v-if="form.errors.wholesale_price" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.wholesale_price }}</div>
            </div>
            <!-- restaurant-only: a cooked dish's "stock" isn't a count anyone
                 can know ahead of time (a pot's yield varies), so this picks
                 which of the 3 ways this product's availability actually works
                 — packaged goods (coke, ice cream) still just pick "সংখ্যা গুনব"
                 and get the exact same numeric field every other vertical has -->
            <div v-if="isRestaurant && !editingHasVariants" class="field">
                <label>{{ t('stock.stockMode') }}</label>
                <div class="seg" style="margin-top:4px">
                    <button type="button" :class="{ on: form.stock_mode === 'untracked' }" @click="form.stock_mode = 'untracked'">{{ t('stock.stockModeUntracked') }}</button>
                    <button type="button" :class="{ on: form.stock_mode === 'toggle' }" @click="form.stock_mode = 'toggle'">{{ t('stock.stockModeToggle') }}</button>
                    <button type="button" :class="{ on: form.stock_mode === 'tracked' }" @click="form.stock_mode = 'tracked'">{{ t('stock.stockModeTracked') }}</button>
                </div>
                <div style="color:var(--dim);font-size:12px;margin-top:4px">
                    {{ form.stock_mode === 'untracked' ? t('stock.stockModeUntrackedHint') : form.stock_mode === 'toggle' ? t('stock.stockModeToggleHint') : t('stock.stockModeTrackedHint') }}
                </div>
            </div>

            <div class="f2">
                <div class="field" v-if="editingHasVariants">
                    <label>{{ t('stock.stock') }}</label>
                    <input :value="`${editing.stock} ${t('stock.pieces')}`" disabled>
                    <div style="color:var(--mut);font-size:12px;margin-top:6px">{{ t('stock.variantStockHint') }}</div>
                </div>
                <div class="field" v-else-if="isRestaurant && form.stock_mode === 'toggle'">
                    <label>{{ t('stock.stockModeToggle') }}</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:8px">
                        <input v-model="form.available" type="checkbox" style="width:auto">
                        {{ form.available ? t('pos.availableToday') : t('pos.soldOutToday') }}
                    </label>
                </div>
                <div class="field" v-else-if="!(isRestaurant && form.stock_mode === 'untracked')">
                    <label>{{ t('stock.stock') }}{{ form.sold_by_weight ? ` (${form.weight_unit === 'litre' ? t('stock.unitLitre') : t('stock.unitKg')})` : '' }}</label>
                    <input v-model="form.stock" type="number" :step="form.sold_by_weight ? 0.001 : 1">
                    <div v-if="form.errors.stock" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.stock }}</div>
                </div>
                <div class="field"><label>{{ t('stock.barcode') }}</label><input v-model="form.barcode"></div>
            </div>
            <div class="field">
                <label>{{ t('stock.discountPrice') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.discountPriceHint') }}</span></label>
                <input v-model="form.discount_price" type="number">
                <div v-if="form.errors.discount_price" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.discount_price }}</div>
            </div>
            <div v-if="hasLowStockAlerts && !(isRestaurant && form.stock_mode !== 'tracked')" class="field">
                <label>{{ t('stock.reorderPoint') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.reorderPointHint') }}</span></label>
                <input v-model="form.reorder_point" type="number" min="0">
                <div v-if="form.errors.reorder_point" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.reorder_point }}</div>
            </div>
            <button class="btn" :disabled="form.processing" @click="saveProduct">
                {{ form.processing ? '...' : t('stock.save') }}
            </button>

            <div v-if="!editing && hasUnitConversion" class="field" style="background:var(--panel2);border-radius:10px;padding:10px 12px;font-size:12.5px;color:var(--mut)">
                💡 {{ t('stock.packSizesAfterSaveHint') }}
            </div>

            <div v-if="editing && hasUnitConversion" class="hr"></div>
            <div v-if="editing && hasUnitConversion" class="field">
                <label>{{ t('stock.packSizes') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.packSizesHint') }}</span></label>
                <div style="font-size:12px;color:var(--mut);margin-bottom:8px;line-height:1.5">{{ t('stock.packSizesExample') }}</div>

                <div v-if="!editing.product_units?.length" style="font-size:12px;color:var(--dim);margin-bottom:8px">{{ t('stock.noPackSizesYet') }}</div>
                <div v-for="pu in editing.product_units" :key="pu.id" class="cart-line">
                    <div class="nm"><b>{{ pu.unit?.name }}</b><span>1 {{ pu.unit?.name }} = {{ pu.factor }} {{ t('stock.pieces') }} · {{ money(pu.price) }} <template v-if="pu.factor">(≈ {{ money(pu.price / pu.factor) }}/{{ t('stock.pieces') }})</template></span></div>
                    <button class="btn sm rose" @click="removePackSize(pu)">✕</button>
                </div>

                <div class="f2" style="margin-top:8px">
                    <select v-model="packForm.unit_id">
                        <option :value="''">{{ t('stock.selectUnit') }}</option>
                        <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <input v-model="packForm.new_unit_name" :placeholder="t('stock.orNewUnit')">
                </div>
                <!-- "1 box = how many pieces" is what's stored, but a shop
                     owner thinks in terms of an already-added pack ("1 box =
                     10 strips"), not the total piece count — pick one here
                     and the piece count on the right fills in for you,
                     still visible/editable, never hidden math -->
                <div v-if="editing.product_units?.length" class="f2" style="margin-top:8px">
                    <select v-model="packBasedOnId">
                        <option :value="''">{{ t('stock.orBasedOnPack') }}</option>
                        <option v-for="pu in editing.product_units" :key="pu.id" :value="pu.id">{{ pu.unit?.name }}</option>
                    </select>
                    <input v-model="packBasedOnCount" type="number" min="1" :placeholder="t('stock.howMany')" :disabled="!packBasedOnId">
                </div>
                <div v-if="packBasedOnUnit && packBasedOnCount" style="font-size:12px;color:var(--sky);margin-top:4px">
                    {{ t('stock.packBasedOnPreview', { count: packBasedOnCount, basedOn: packBasedOnUnit.unit?.name, pieces: Number(packBasedOnCount) * packBasedOnUnit.factor }) }}
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="packForm.factor" type="number" min="2" :placeholder="t('stock.howManyPieces')">
                    <input v-model="packForm.price" type="number" :placeholder="t('stock.wholePackPrice')">
                </div>
                <div v-if="packSizePreview" style="font-size:12px;color:var(--green);margin-top:6px;font-weight:600">
                    ✓ {{ t('stock.packSizePreview', { perPiece: money(packSizePreview.perPiece) }) }}
                </div>
                <div v-if="packForm.errors.unit_id || packForm.errors.factor || packForm.errors.price" style="color:var(--rose);font-size:12px;margin-top:6px">
                    {{ packForm.errors.unit_id || packForm.errors.factor || packForm.errors.price }}
                </div>
                <button class="btn ghost sm" style="width:100%;margin-top:8px" :disabled="packForm.processing" @click="addPackSize">
                    {{ t('stock.addPackSize') }}
                </button>
            </div>

            <div v-if="editing && hasProductVariants" class="hr"></div>
            <div v-if="editing && hasProductVariants" class="field">
                <label>{{ t('stock.variants') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.variantsHint') }}</span></label>
                <div v-for="v in editing.variants" :key="v.id" class="cart-line" style="align-items:flex-start">
                    <div class="nm">
                        <b>{{ variantLabel(v) }}</b>
                        <span>{{ v.stock }} {{ t('stock.pieces') }}<template v-if="v.price"> • {{ money(v.price) }}</template><template v-if="v.barcode"> • 🏷️ {{ v.barcode }}</template><template v-if="v.reorder_point"> • ⚠ {{ v.reorder_point }}</template></span>
                        <div style="display:flex;gap:6px;margin-top:6px">
                            <input v-model="variantStockInQty[v.id]" type="number" :placeholder="t('stock.stockIn')" style="width:90px">
                            <button class="btn sm ghost" @click="stockInVariant(v)">+</button>
                        </div>
                    </div>
                    <button class="btn sm rose" @click="removeVariant(v)">✕</button>
                </div>
                <button v-if="!gridOpen" class="btn sm" style="width:100%;margin-top:10px" @click="openGrid">
                    {{ t('stock.addVariantGrid') }}
                </button>

                <div v-if="gridOpen" style="margin-top:10px;padding:10px;border:1px dashed var(--line);border-radius:10px">
                    <div style="font-size:12px;color:var(--mut);margin-bottom:8px">{{ t('stock.gridHint') }}</div>
                    <div class="f2">
                        <input v-model="gridColorsInput" :placeholder="t('stock.gridColorsPlaceholder')">
                        <input v-model="gridSizesInput" :placeholder="t('stock.gridSizesPlaceholder')">
                    </div>

                    <div v-if="gridColors.length || gridSizes.length" style="overflow-x:auto;margin-top:10px">
                        <table style="border-collapse:collapse;width:100%">
                            <thead>
                                <tr>
                                    <th style="text-align:left;font-size:11px;color:var(--dim);padding:4px 6px"></th>
                                    <th v-for="size in (gridSizes.length ? gridSizes : [''])" :key="'h-' + size" style="font-size:12px;padding:4px 6px;text-align:center">{{ size || t('stock.size') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="color in (gridColors.length ? gridColors : [''])" :key="'r-' + color">
                                    <td style="font-size:12px;padding:4px 6px;white-space:nowrap">{{ color || t('stock.color') }}</td>
                                    <td v-for="size in (gridSizes.length ? gridSizes : [''])" :key="'c-' + color + size" style="padding:3px">
                                        <input
                                            v-model="gridStock[gridCellKey(color, size)]"
                                            type="number" min="0" placeholder="0"
                                            style="width:56px;text-align:center;margin:0;padding:6px 4px"
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="gridError" style="color:var(--rose);font-size:12px;margin-top:8px">{{ gridError }}</div>
                    <div style="display:flex;gap:8px;margin-top:10px">
                        <button class="btn sm" style="flex:1" :disabled="gridProcessing" @click="submitGrid">{{ gridProcessing ? '...' : t('stock.gridSubmit') }}</button>
                        <button class="btn sm ghost" @click="gridOpen = false">{{ t('common.cancel') }}</button>
                    </div>
                </div>

                <div class="hr"></div>
                <div style="font-size:12px;color:var(--dim);margin-bottom:6px">{{ t('stock.addVariantSingle') }}</div>
                <div class="f2">
                    <input v-model="variantForm.size" :placeholder="t('stock.size')">
                    <input v-model="variantForm.color" :placeholder="t('stock.color')">
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="variantForm.stock" type="number" :placeholder="t('stock.startingStock')">
                    <input v-model="variantForm.price" type="number" :placeholder="t('stock.variantPriceOptional')">
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="variantForm.barcode" :placeholder="t('stock.variantBarcodeOptional')">
                    <input v-model="variantForm.reorder_point" type="number" min="0" :placeholder="t('stock.variantReorderOptional')">
                </div>
                <div v-if="variantForm.errors.size" style="color:var(--rose);font-size:12px;margin-top:6px">{{ variantForm.errors.size }}</div>
                <button class="btn ghost sm" style="width:100%;margin-top:8px" :disabled="variantForm.processing" @click="addVariant">
                    {{ t('stock.addVariant') }}
                </button>
            </div>

            <button v-if="editing" class="btn rose" style="margin-top:10px" @click="deleteProduct">{{ isRestaurant ? t('stock.deleteProductRestaurant') : t('stock.deleteProduct') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="productSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="stockInSheet" :title="t('stock.stockInTitle')">
            <div v-if="stockInProduct" class="card" style="margin-bottom:14px">
                <b>{{ stockInProduct.emoji }} {{ stockInProduct.name }}</b>
                <div style="color:var(--mut);font-size:12px;margin-top:4px">{{ t('stock.currentStock') }} {{ formatQty(stockInProduct, stockInProduct.stock) }} {{ unitLabel(stockInProduct) }}</div>
            </div>
            <div v-if="stockInProduct?.product_units?.length" class="field" style="background:var(--panel2);border-radius:10px;padding:8px 10px;font-size:12px;color:var(--mut)">
                {{ t('stock.stockInPacksHint', { pieces: unitLabel(stockInProduct) }) }}
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('stock.howManyArrived') }} ({{ unitLabel(stockInProduct) }})</label><input v-model="stockInQty" type="number" :step="stockInProduct?.sold_by_weight ? 0.001 : 1"></div>
                <div class="field"><label>{{ t('stock.costOptional') }}</label><input v-model="stockInCost" type="number" step="0.01"></div>
            </div>
            <div v-if="hasBatchTracking" class="f2">
                <div class="field"><label>{{ t('stock.batchNo') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="stockInBatchNo"></div>
                <div class="field"><label>{{ t('stock.expiryDate') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="stockInExpiryDate" type="date"></div>
            </div>
            <template v-if="hasSerialTracking">
                <div class="field">
                    <label>{{ t('stock.imeis') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.imeisHint') }}</span></label>
                    <textarea v-model="stockInImeis" rows="3" :placeholder="t('stock.imeisPlaceholder')"></textarea>
                    <div v-if="page.props.errors?.imeis" style="color:var(--rose);font-size:12px;margin-top:6px">{{ page.props.errors.imeis }}</div>
                </div>
                <div class="field"><label>{{ t('stock.warrantyUntil') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="stockInWarrantyExpiry" type="date"></div>
            </template>
            <button class="btn" :disabled="stockInSubmitting" @click="applyStockIn">
                {{ stockInSubmitting ? '...' : t('stock.addToStock') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="stockInSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="importSheet" :title="t('stock.importCsv')" :subtitle="isRestaurant ? t('stock.importCsvSubRestaurant') : t('stock.importCsvSub')">
            <a :href="route('app.products.import.template')" class="btn ghost sm" style="margin-bottom:14px;display:inline-block">{{ t('stock.downloadTemplate') }}</a>
            <div class="field">
                <label>{{ t('stock.chooseFile') }}</label>
                <input type="file" accept=".csv,text/csv" @change="importForm.file = $event.target.files[0]">
                <div v-if="importForm.errors.file" style="color:var(--rose);font-size:12px;margin-top:6px">{{ importForm.errors.file }}</div>
            </div>
            <button class="btn" :disabled="importForm.processing || !importForm.file" @click="saveImport">
                {{ importForm.processing ? '...' : t('stock.importSubmit') }}
            </button>
            <button class="btn ghost" style="margin-top:10px" @click="importSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
