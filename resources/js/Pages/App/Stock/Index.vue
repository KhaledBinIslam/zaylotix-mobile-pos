<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import HowToHint from '@/Components/HowToHint.vue';
import SmartScanSheet from '@/Components/SmartScanSheet.vue';
import { useI18n } from '@/composables/useI18n';
import { formatSize } from '@/support/sizeDisplay';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { usePollingReload } from '@/composables/usePollingReload';
import { useToast } from '@/composables/useToast';
import { colorGroups } from '@/support/productDisplay';
import { variantHasSupplier } from '@/support/clientProductSearch';
import { usePermissions } from '@/composables/usePermissions';
import { reportClientError } from '@/support/reportClientError';

const props = defineProps({
    products: Object, categories: Array, units: Array, stats: Object, q: String, categoryId: [Number, String],
    sizeSets: { type: Array, default: () => [] },
    brands: { type: Array, default: null }, // null = brands feature off, field hidden
    suppliers: { type: Array, default: null }, // null = suppliers feature off, selects hidden
    company: String, genericName: String, companies: { type: Array, default: () => [] }, genericNames: { type: Array, default: () => [] },
    stockStatus: String,
    supplierId: [Number, String],
    knownColors: { type: Array, default: () => [] },
    knownSizes: { type: Array, default: () => [] },
    // which categories price by size group, and the groups themselves —
    // so adding a size to an article later offers the SAME price boxes
    // the article form offered when it was created
    sizeGroupCategoryCodes: { type: Array, default: () => [] },
    kidsShoeSizeGroups: { type: Array, default: () => [] },
});

const page = usePage();
const { hasPerm } = usePermissions();
const features = computed(() => page.props.features || []);
const hasUnitConversion = computed(() => features.value.includes('unit_conversion'));
const hasLowStockAlerts = computed(() => features.value.includes('low_stock_alerts'));
const hasBatchTracking = computed(() => features.value.includes('batch_tracking'));
const hasProductVariants = computed(() => features.value.includes('product_variants'));
const hasSerialTracking = computed(() => features.value.includes('serial_tracking'));
const hasPrescriptionRecords = computed(() => features.value.includes('prescription_records'));
const hasWeightBasedSelling = computed(() => features.value.includes('weight_based_selling'));
const hasWholesalePricing = computed(() => features.value.includes('wholesale_pricing'));
const hasWholesaleTiers = computed(() => features.value.includes('wholesale_tiers'));
// gates the stock-mode picker below — a cooked dish's "stock" doesn't mean
// a count the way it does for every other vertical (see Product::STOCK_MODE_*
// and TableOrderController), so only a restaurant shop's form offers it;
// every other business type's stock field stays exactly as it always was.
// Deliberately the shop's actual business type, NOT the restaurant_tables
// feature flag — see AppLayout.vue's isRestaurant for why the flag alone is
// wrong (it can be granted to a non-restaurant shop, e.g. the flagship demo).
const isRestaurant = computed(() => page.props.shop?.business_type_slug === 'restaurant');
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// search/category filter now run server-side (see ProductController::index)
// — necessary once the list is paginated, since a client-side filter can
// only ever see whatever page happened to load, not the whole catalog
const q = ref(props.q || '');
const searchInput = ref(null);
const cat = ref(props.categoryId || 'all');
const companyFilter = ref(props.company || '');
const genericFilter = ref(props.genericName || '');
const supplierFilter = ref(props.supplierId || '');
const filtered = computed(() => props.products.data);
function applyFilter() {
    router.get(route('app.stock'), {
        q: q.value || undefined,
        category_id: cat.value === 'all' ? undefined : cat.value,
        company: companyFilter.value || undefined,
        generic_name: genericFilter.value || undefined,
        supplier_id: supplierFilter.value || undefined,
        stock_status: props.stockStatus || undefined,
    }, { preserveState: true, preserveScroll: true });
}
// reported: this screen's own low-stock/out-of-stock tiles showed a count
// but tapping did nothing either - filters the same list down to exactly
// those products (toggles off if the same tile is tapped again)
function filterByStockStatus(status) {
    router.get(route('app.stock'), {
        q: q.value || undefined,
        category_id: cat.value === 'all' ? undefined : cat.value,
        company: companyFilter.value || undefined,
        generic_name: genericFilter.value || undefined,
        supplier_id: supplierFilter.value || undefined,
        stock_status: props.stockStatus === status ? undefined : status,
    }, { preserveState: true, preserveScroll: true });
}

// Camera barcode search — reported: this screen's search box should work
// the same way POS's already does (name/barcode text search + a camera
// scan option), so an owner can scan a shelf tag to jump straight to that
// product instead of typing it out. Simpler than POS's own scanner (no
// cart to add to, no variant/invoice special-casing) - a decoded barcode
// just becomes the search term and re-runs the normal server search above.
const scannerOpen = ref(false);
const scanHint = ref('');
let html5Qrcode = null;
async function openScanner() {
    scannerOpen.value = true;
    scanHint.value = t('pos.scanHintDefault');
    try {
        const { Html5Qrcode } = await import('html5-qrcode');
        html5Qrcode = new Html5Qrcode('stock-reader', { verbose: false });
        await html5Qrcode.start(
            { facingMode: 'environment' },
            { fps: 12, qrbox: { width: 260, height: 200 } },
            (decodedText) => {
                if (navigator.vibrate) navigator.vibrate(40);
                q.value = decodedText.trim();
                closeScanner();
                applyFilter();
            },
            () => {},
        );
    } catch (e) {
        toast('❌ ' + t('pos.cameraNotFound'));
        closeScanner();
    }
}
async function closeScanner() {
    scannerOpen.value = false;
    if (html5Qrcode) {
        try { await html5Qrcode.stop(); html5Qrcode.clear(); } catch (e) { /* already stopped */ }
        html5Qrcode = null;
    }
}
function setCategory(id) {
    cat.value = id;
    applyFilter();
}

// a weighed product's own "low" threshold is 1 kg/litre, not 6 pieces —
// 6 loose grams left on the shelf would be meaningless as a low-stock signal
// Real bug Khaled hit: this ignored a product's own reorder_point entirely
// (always 6/1), and only ever looked at the product's own TOTAL stock, so
// an article with one size/color down to its own threshold (but a healthy
// combined total) never showed as low here — mirrors the same fix in
// Product::scopeLowStock() server-side, so the badge and the "Low Stock"
// count/filter never disagree.
function lowThreshold(p) {
    const generic = p.sold_by_weight ? 1 : 6;
    return Number(p.reorder_point) > 0 ? Number(p.reorder_point) : generic;
}
function hasALowVariant(p) {
    return (p.variants || []).some((v) => Number(v.reorder_point) > 0 && Number(v.stock) > 0 && Number(v.stock) <= Number(v.reorder_point));
}
// computed server-side against the WHOLE catalog (see ProductController) —
// these are dashboard totals, not a summary of whichever page is showing
const totalProducts = computed(() => props.stats.total);
const lowStockCount = computed(() => props.stats.low_stock);
const outOfStockCount = computed(() => props.stats.out_of_stock);
const expiringSoonCount = computed(() => props.stats.expiring_soon);
const categoryCounts = computed(() => props.stats.category_counts);

// --- SEAMAN Phase 2: category → sub-category (flat shops unaffected:
// every category is top-level, childrenOf() is always empty) ---
const topCategories = computed(() => (props.categories || []).filter((c) => !c.parent_id));
const childrenOf = (id) => (props.categories || []).filter((c) => c.parent_id === id);
/** The sub-chip row belongs to whichever parent the current selection lives under. */
const subParentId = computed(() => {
    if (cat.value === 'all') return null;
    const selected = (props.categories || []).find((c) => c.id === cat.value);
    return selected ? (selected.parent_id || selected.id) : null;
});
const subChips = computed(() => (subParentId.value ? childrenOf(subParentId.value) : []));
/** A parent tab's count covers its whole family — "Shoe" counts every kind of shoe. */
const countWithChildren = (id) => (categoryCounts.value[id] || 0) + childrenOf(id).reduce((s, c) => s + (categoryCounts.value[c.id] || 0), 0);

// Root-caused live (2026-09-23, real-user report): a non-weight-based
// product's stock badge on every row here always said "পিস" (pieces),
// regardless of what unit the owner actually picked for it (কেজি, লিটার,
// প্যাকেট, বক্স, সেট, ...) — this hardcoded 'stock.pieces' unconditionally
// instead of reading the product's own p.unit relation, which every other
// unit-aware spot in this app (POS's product cards, this same file's own
// edit form) already reads correctly. A General Store owner who set a
// product's unit to কেজি (without also knowing about the separate
// sold_by_weight loose-selling toggle — a different concept, not implied
// by picking a unit) would see it labeled "পিস" everywhere this function
// is used, looking exactly like "my kg got silently changed to piece".
// Falls back to the generic pieces label only when the product genuinely
// has no unit set at all, same fallback POS already uses.
function weightUnitLabel(unit) {
    if (unit === 'litre') return t('stock.unitLitre');
    if (unit === 'meter') return t('stock.unitMeter');
    return t('stock.unitKg');
}
function unitLabel(p) {
    if (!p.sold_by_weight) return p.unit?.name || t('stock.pieces');
    return weightUnitLabel(p.weight_unit);
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
    if (stock <= lowThreshold(p) || hasALowVariant(p)) return { cls: 'gold', text: `${t('stock.lowStock')} ${formatQty(p, stock)} ${unitLabel(p)}` };
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
// A product with stock already on it, but no variant yet, has that stock
// sitting unattributed to any size/color — the FIRST variant(s) created on
// it must account for every one of those units (allocate it, or explicitly
// write off whatever's short as damage), or products.stock silently drifts
// away from SUM(variants.stock) forever (see ProductVariantController::
// reconcilePreExistingStock — this is the Salenoir bug).
const preExistingUnallocatedStock = computed(() => (!editingHasVariants.value && Number(editing.value?.stock) > 0) ? Number(editing.value.stock) : 0);
const form = useForm({
    name: '', name_en: '', generic_name: '', company: '', shelf_location: '', requires_prescription: false, emoji: '📦', photo: null, remove_photo: false, category_id: '', new_category_name: '',
    option1_label: '', option2_label: '', brand_id: '', supplier_id: '', internal_note: '',
    unit_id: '', new_unit_name: '', barcode: '', cost: '', price: '', wholesale_price: '', wholesale_moq: '', discount_price: '', stock: '',
    reorder_point: '', sold_by_weight: false, weight_unit: 'kg', track_serial: false,
    // restaurant-only (see isRestaurant) — 'tracked' everywhere else,
    // unchanged from before this field existed
    stock_mode: 'tracked', available: true,
    // quantity-break wholesale prices — only meaningful under
    // wholesale_tiers (see docs/FIX_PLAN.md's Phase 30 design note); an
    // array of {min_qty, price} rows, empty for a product with none set
    price_tiers: [],
});

// a blank sheet to append to price_tiers — kept as a plain function (not a
// shared constant) so each push gets its own object, never one shared
// reference several rows would silently mutate together
function blankTier() {
    return { min_qty: '', price: '' };
}
function addTier() {
    form.price_tiers.push(blankTier());
}
function removeTier(index) {
    form.price_tiers.splice(index, 1);
}

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

// --- smart scan (camera barcode check / OCR label capture) — see
// SmartScanSheet.vue's own docblock. Only ever pre-fills the still-open,
// still-editable Add Product form; nothing here is ever saved on its own. ---
const smartScanOpen = ref(false);
function onSmartScanDetected(fields) {
    if (fields.name) form.name = fields.name;
    if (fields.generic_name) form.generic_name = fields.generic_name;
    if (fields.company) form.company = fields.company;
    if (fields.price) form.price = fields.price;
    if (fields.barcode) form.barcode = fields.barcode;
    toast('✅ ' + t('smartScan.appliedToast'));
}
function onSmartScanBarcodeFound({ barcode, product }) {
    form.barcode = barcode;
    toast('ℹ ' + t('smartScan.alreadyExistsToast', { name: product.name + (product.variant_label ? ` (${product.variant_label})` : '') }));
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
    form.option1_label = p.option1_label || ''; form.option2_label = p.option2_label || '';
    form.brand_id = p.brand_id || ''; form.supplier_id = p.supplier_id || ''; form.internal_note = p.internal_note || '';
    form.cost = p.cost; form.price = p.price; form.wholesale_price = p.wholesale_price; form.wholesale_moq = p.wholesale_moq; form.discount_price = p.discount_price;
    form.price_tiers = (p.price_tiers || []).map(tier => ({ min_qty: tier.min_qty, price: tier.price }));
    // p.stock comes back from the server as a decimal-cast string ("124.000")
    // even for a piece-counted product — prefilling that raw into the form
    // meant just opening and re-saving without touching this field failed
    // the backend's `integer` rule (Laravel's integer check rejects any
    // decimal point, trailing zeros or not). Only a genuinely weighed
    // product's stock should keep its fractional precision here.
    form.stock = p.sold_by_weight ? p.stock : Math.round(Number(p.stock));
    form.reorder_point = p.reorder_point;
    form.stock_mode = p.stock_mode || 'tracked';
    form.available = Number(p.stock) > 0; // only meaningful when stock_mode is 'toggle' — see Product::isMarkedAvailable()
    form.sold_by_weight = !!p.sold_by_weight; form.weight_unit = p.weight_unit || 'kg';
    form.track_serial = !!p.track_serial;
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
const variantForm = useForm({ size: '', color: '', barcode: '', stock: '', reorder_point: '', price: '', cost: '', write_off_rest: false });
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
const gridWriteOffRest = ref(false);
const gridTotal = computed(() => Object.values(gridStock.value).reduce((sum, v) => sum + (Number(v) || 0), 0));
// one selling + buying price for the whole grid, or one pair per size
// group when the article is priced that way — left blank, each variant
// inherits the article's own, exactly as before
const gridPrice = ref('');
const gridCost = ref('');
const gridGroupPrices = ref({});
const gridGroupCosts = ref({});

const editingCategory = computed(() => (props.categories || []).find((c) => c.id === editing.value?.category_id) || null);

// --- SEAMAN Phase 3: named option axes + size sets + per-cell prices ---
const hasVariantOptions = computed(() => features.value.includes('variant_options'));
const hasSourceLines = computed(() => features.value.includes('source_lines'));

/**
 * Khaled live: the list row's 🚚 badge read the product's single, one-time
 * supplier_id — stuck at whoever was set first, even once a source-lines
 * product genuinely has several real suppliers across its lines ("ekhon
 * sob Bulu dekhacche"). This reads the actual lines instead: every
 * distinct supplier across the product's own sources and every variant's
 * — one name shown plainly, several shown as a count + the names
 * (Khaled's own call), and a product with no lines yet falls back to the
 * old single field so nothing regresses for data not yet touched by Part 1.
 */
function productSupplierSummary(p) {
    if (!hasSourceLines.value) return p.supplier?.name || 'Nil';

    const names = [];
    for (const line of p.sources || []) {
        if (line.supplier?.name && !names.includes(line.supplier.name)) names.push(line.supplier.name);
    }
    for (const v of p.variants || []) {
        for (const line of v.sources || []) {
            if (line.supplier?.name && !names.includes(line.supplier.name)) names.push(line.supplier.name);
        }
    }
    if (!names.length) return p.supplier?.name || 'Nil';
    if (names.length === 1) return names[0];

    return t('stock.supplierCount', { n: names.length, names: names.join(', ') });
}

// Part 1 — "add stock" sheet: supplier(+brand) + qty + buying price, one
// screen, as few taps as possible. Remembers the last supplier picked on
// THIS device (a per-viewer convenience, never shop-wide data) so the
// cashier doesn't re-pick Manik every single time they restock from him.
const SOURCE_SUPPLIER_KEY = 'zaylotix.lastSourceSupplier';
const sourceSheet = ref(false);
const sourceTarget = ref(null); // { kind: 'variant'|'product', id, label, lines }
// Khaled live: "koto tk pay korche ... koto due ache show hobe" — same
// 3-way choice as every other stock-in screen now has
const sourceForm = useForm({ supplier_id: '', brand_id: '', qty: '', cost: '', price: '', payment_method: 'cash' });
function openSourceStockIn(kind, target) {
    sourceTarget.value = { kind, id: target.id, label: kind === 'variant' ? variantLabel(target) : target.name, lines: target.sources || [] };
    sourceForm.reset();
    sourceForm.clearErrors();
    sourceForm.payment_method = 'cash';
    try {
        sourceForm.supplier_id = localStorage.getItem(SOURCE_SUPPLIER_KEY) || '';
    } catch { /* private-window/blocked storage — just starts blank */ }
    sourceSheet.value = true;
}
function saveSourceStockIn() {
    try {
        if (sourceForm.supplier_id) localStorage.setItem(SOURCE_SUPPLIER_KEY, sourceForm.supplier_id);
    } catch { /* per-viewer convenience only — never blocks the save */ }
    const routeName = sourceTarget.value.kind === 'variant' ? 'app.productSources.stockInVariant' : 'app.productSources.stockInProduct';
    sourceForm.post(route(routeName, sourceTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { sourceSheet.value = false; },
    });
}
// Correction tools — "pore kono ta stock tulte vul hole ... tik [kora]":
// a wrongly-filed lot moved to the right supplier(+brand), and stock that
// predates line tracking (mirror stock > sum of lines — e.g. Sujon pieces
// added through the quick "+" before it wrote lines) claimed for one.
// Neither is a stock-in: total stock, payable and the ledger don't move.
function untrackedQty(target) {
    const mirror = Number(target.stock) || 0;
    const tracked = (target.sources || []).reduce((s, l) => s + (Number(l.stock) || 0), 0);
    return Math.max(0, Math.round((mirror - tracked) * 1000) / 1000);
}

// Khaled live: "ek product e kon supplier koi ta product total dekha jai
// ebong oikhan tekhe selct kore easily barcode print" — one glance at
// whose goods a product holds, and that supplier's stickers one tap away.
// Also what makes a WRONG entry jump out ("vul stock tulle easily dhora").
function supplierBreakdown(p) {
    const map = new Map();
    const add = (line) => {
        const qty = Number(line.stock) || 0;
        if (qty <= 0) return;
        const id = line.supplier?.id ?? line.supplier_id ?? 0;
        const cur = map.get(id) || { id: id || null, name: line.supplier?.name || 'Nil', qty: 0 };
        cur.qty += qty;
        map.set(id, cur);
    };
    (p.sources || []).forEach(add);
    (p.variants || []).forEach((v) => (v.sources || []).forEach(add));
    return [...map.values()];
}
function productUntracked(p) {
    const tracked = supplierBreakdown(p).reduce((s, x) => s + x.qty, 0);
    return Math.max(0, Math.round(((Number(p.stock) || 0) - tracked) * 1000) / 1000);
}
// Khaled: "supplier filter korle shudu ey filter dekhanor kotha" — with a
// supplier picked up top, the edit sheet shows only that supplier's lines.
// Khaled live: he was picking "Sujon" in the sheet's OWN "Supplier for the
// quick +boxes below" field (variantStockInSupplier) expecting the SAME
// narrowing, since from inside the sheet it reads as "I selected a
// supplier" either way — the page-level filter alone left that pick with
// no visible effect. variantStockInSupplier only wins when the page-level
// filter isn't already narrowing things (that one still takes priority).
function editVisibleLines(target) {
    const lines = target.sources || [];
    const supplierId = supplierFilter.value || variantStockInSupplier.value;
    if (!supplierId) return lines;

    return lines.filter((l) => (l.supplier?.id ?? l.supplier_id) === Number(supplierId));
}
const fixSheet = ref(false);
const fixMode = ref('move'); // 'move' (re-file a lot) | 'claim' (untracked stock)
const fixTarget = ref(null);
const fixForm = useForm({ supplier_id: '', brand_id: '', qty: '', cost: '', price: '' });
function openMoveLine(line, label) {
    fixMode.value = 'move';
    fixTarget.value = { line, label };
    fixForm.reset();
    fixForm.clearErrors();
    fixForm.qty = String(Math.floor(Number(line.stock)) || '');
    fixSheet.value = true;
}
function openClaim(kind, target, label) {
    fixMode.value = 'claim';
    fixTarget.value = { kind, id: target.id, label };
    fixForm.reset();
    fixForm.clearErrors();
    fixForm.qty = String(untrackedQty(target) || '');
    fixForm.cost = target.cost ?? editing.value?.cost ?? '';
    fixForm.price = target.price ?? editing.value?.price ?? '';
    fixSheet.value = true;
}
function saveFix() {
    const url = fixMode.value === 'move'
        ? route('app.productSources.move', fixTarget.value.line.id)
        : route(fixTarget.value.kind === 'variant' ? 'app.productSources.claimVariant' : 'app.productSources.claimProduct', fixTarget.value.id);
    fixForm.post(url, { preserveScroll: true, onSuccess: () => { fixSheet.value = false; } });
}

// SEAMAN: "buy and sell price vul dichi eta update korar option nai" —
// fix a mistyped cost/price on one supplier's own lot, in place. Reuses
// the exact same supplier-filtered rows Khaled already narrows to (see
// editVisibleLines()'s own comment) — select a supplier up top, open the
// product, every size/colour's line for THAT supplier is what's on screen.
const editSourceSheet = ref(false);
const editSourceTarget = ref(null); // { line, label }
const editSourceForm = useForm({ cost: '', price: '' });
function openEditSource(line, label) {
    editSourceTarget.value = { line, label };
    editSourceForm.reset();
    editSourceForm.clearErrors();
    editSourceForm.cost = String(line.cost ?? '');
    editSourceForm.price = String(line.price ?? '');
    editSourceSheet.value = true;
}
function saveEditSource() {
    const line = editSourceTarget.value.line;
    editSourceForm.patch(route('app.productSources.update', line.id), {
        preserveScroll: true,
        onSuccess: () => {
            // the edit sheet underneath is still open on the SAME product —
            // a full page reload replaces props.products with fresh
            // objects, which this still-open sheet's `editing` ref never
            // re-points to, so without this the fix looked like it did
            // nothing until the sheet was closed and reopened
            line.cost = Number(editSourceForm.cost) || 0;
            line.price = editSourceForm.price !== '' ? Number(editSourceForm.price) : line.price;
            editSourceSheet.value = false;
        },
    });
}

// SEAMAN: "ekta product delete korte hole full product delete korte
// hocche, ami caile kon supplier er delete korte cacchi" — remove just
// this one supplier's lot, not the whole product/variant.
function deleteSource(line) {
    const msg = Number(line.stock) > 0
        ? t('os.deleteLineConfirm', { stock: line.stock })
        : t('os.deleteLineConfirmEmpty');
    if (!confirm(msg)) return;
    router.delete(route('app.productSources.destroy', line.id), { preserveScroll: true });
}

// Baby Park live: "can't remove just 1 piece without deleting the whole
// variant" — Stock −/Set exact, both backed by one server-side correction
// path (ProductVariantController::decreaseStock/setExactStock) so they can
// never disagree about what a correction means.
const decreaseSheet = ref(false);
const decreaseTarget = ref(null); // { variant, label }
const decreaseForm = useForm({ qty: '', reason: 'wrong_entry', note: '' });
function openDecrease(v) {
    decreaseTarget.value = { variant: v, label: variantLabel(v) };
    decreaseForm.reset();
    decreaseForm.clearErrors();
    decreaseSheet.value = true;
}
function saveDecrease() {
    decreaseForm.post(route('app.productVariants.decreaseStock', decreaseTarget.value.variant.id), {
        preserveScroll: true,
        onSuccess: () => { decreaseSheet.value = false; },
    });
}

const exactSheet = ref(false);
const exactTarget = ref(null);
const exactForm = useForm({ stock: '' });
function openExact(v) {
    exactTarget.value = { variant: v, label: variantLabel(v) };
    exactForm.reset();
    exactForm.clearErrors();
    exactForm.stock = String(Math.floor(Number(v.stock)) || 0);
    exactSheet.value = true;
}
function saveExact() {
    exactForm.post(route('app.productVariants.setExactStock', exactTarget.value.variant.id), {
        preserveScroll: true,
        onSuccess: () => { exactSheet.value = false; },
    });
}

// per-variant history — fetched on open, not preloaded with the page
const historySheet = ref(false);
const historyTarget = ref(null);
const historyRows = ref([]);
const historyLoading = ref(false);
async function openHistory(v) {
    historyTarget.value = { label: variantLabel(v) };
    historyRows.value = [];
    historySheet.value = true;
    historyLoading.value = true;
    try {
        const res = await fetch(route('app.productVariants.history', v.id), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        historyRows.value = data.rows || [];
    } catch (e) { /* the sheet just stays empty — not worth a toast for a read-only peek */ }
    finally { historyLoading.value = false; }
}
function historyWhen(iso) {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('bn-BD', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** What this product calls its two axes — রং/সাইজ unless it renamed them. */
const opt1Label = computed(() => editing.value?.option1_label || t('stock.color'));
const opt2Label = computed(() => editing.value?.option2_label || t('stock.size'));
/** The size set a category suggests: its own pick, else its parent's. A suggestion only — the owner edits the box freely. */
function defaultSizesFor(category) {
    if (!category) return null;
    const setId = category.size_set_id
        || (props.categories || []).find((c) => c.id === category.parent_id)?.size_set_id;
    const set = setId ? (props.sizeSets || []).find((s) => s.id === setId) : null;
    return set ? set.sizes.join(', ') : null;
}
/** Phase 4 — set one variant's brand (the helmet case) without touching anything else on it; size/color ride along because the endpoint's own at-least-one guard reads them. */
function setVariantBrand(v, brandId) {
    router.put(route('app.productVariants.update', v.id), {
        size: v.size, color: v.color, brand_id: brandId || null,
    }, { preserveScroll: true, preserveState: true });
}

// Khaled live: "diffrent color size asle ami kotteke tulbo? ... stock
// tulle kar nam e uthb?" — the bulk grid says whose goods these are
const gridSupplier = ref('');
const gridBrand = ref('');
const gridPaymentMethod = ref('cash');

// per-cell own price/cost (epaulettes: every Rank × Type sells at its own number)
const gridPerCell = ref(false);
const gridCellPrice = ref({});
const gridCellCost = ref({});
const editingUsesSizeGroups = computed(() => {
    const code = String(editingCategory.value?.code ?? '').trim().toUpperCase();
    return code !== '' && (props.sizeGroupCategoryCodes || []).includes(code);
});

function gridCellKey(color, size) {
    return `${color}|${size}`;
}
function openGrid() {
    gridOpen.value = true;
    gridStock.value = {};
    gridError.value = '';
    gridWriteOffRest.value = false;
    gridPrice.value = '';
    gridCost.value = '';
    gridGroupPrices.value = {};
    gridGroupCosts.value = {};
    gridPerCell.value = false;
    gridCellPrice.value = {};
    gridCellCost.value = {};
    // starts on the product's own default supplier — visible and changeable,
    // so "kar nam e uthbe" is never a silent guess
    gridSupplier.value = editing.value?.supplier_id || '';
    gridBrand.value = editing.value?.brand_id || '';
    gridPaymentMethod.value = 'cash';
    // Pre-fill from the shop's saved defaults (Accounts page) only when the
    // owner hasn't already typed something this session — never overwrite
    // an in-progress edit. The category's size set (sub overrides parent)
    // beats the shop-wide default, because it was picked for exactly this
    // kind of product (SEAMAN Phase 3).
    if (!gridSizesInput.value) {
        const fromSet = defaultSizesFor(editingCategory.value);
        if (fromSet) gridSizesInput.value = fromSet;
    }
    if (!gridSizesInput.value && page.props.shop?.default_variant_sizes) gridSizesInput.value = page.props.shop.default_variant_sizes;
    if (!gridColorsInput.value && page.props.shop?.default_variant_colors) gridColorsInput.value = page.props.shop.default_variant_colors;
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
            const row = { color: color || null, size: size || null, stock: Number(qty) };
            if (gridPerCell.value) {
                const cp = gridCellPrice.value[gridCellKey(color, size)];
                const cc = gridCellCost.value[gridCellKey(color, size)];
                if (cp !== undefined && cp !== '') row.price = Number(cp);
                if (cc !== undefined && cc !== '') row.cost = Number(cc);
            }
            variants.push(row);
        }
    }
    if (!variants.length) {
        gridError.value = t('stock.gridNeedsAtLeastOneCell');
        return;
    }

    // only the shape that applies is sent; the other stays empty so the
    // server never has to guess which one the owner meant
    const payload = {
        variants,
        write_off_rest: gridWriteOffRest.value,
        supplier_id: gridSupplier.value || null,
        brand_id: gridBrand.value || null,
        payment_method: gridSupplier.value ? gridPaymentMethod.value : null,
    };
    if (editingUsesSizeGroups.value) {
        payload.group_prices = { ...gridGroupPrices.value };
        payload.group_costs = { ...gridGroupCosts.value };
    } else {
        payload.price = gridPrice.value === '' ? null : Number(gridPrice.value);
        payload.cost = gridCost.value === '' ? null : Number(gridCost.value);
    }

    gridProcessing.value = true;
    router.post(route('app.productVariants.bulkStore', editing.value.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            gridOpen.value = false;
            gridColorsInput.value = '';
            gridSizesInput.value = '';
            gridStock.value = {};
            gridWriteOffRest.value = false;
            gridPrice.value = '';
            gridCost.value = '';
            gridGroupPrices.value = {};
            gridGroupCosts.value = {};
        },
        onError: (errors) => { gridError.value = errors.size || errors.stock || ''; },
        onFinish: () => { gridProcessing.value = false; },
    });
}
function removeVariant(v) {
    // Baby Park live: "must never silently wipe the stock" — the delete
    // already correctly folds this variant's stock out of the product's
    // own total (not a silent wipe), but nobody could SEE that count
    // before confirming. Now they can.
    if (!confirm(`"${variantLabel(v)}" — ${t('stock.pieces')}: ${v.stock}. ${t('stock.removeVariantConfirm')}`)) return;
    router.delete(route('app.productVariants.destroy', v.id), { preserveScroll: true });
}

/** SkyBuy-style color picker (docs/ARTICLE_SERIES_PLAN.md §4) — groups the flat variant list below by color so each color's photo shows once, not per size. */
// Khaled live: "supplier select korle shudu oi supplier er j product asbe
// baki gula show korbena" — the page-level supplier filter already narrows
// WHICH products appear, but a product that matched (because SOME of its
// sizes/colors belong to that supplier) still showed every one of its
// variant rows once opened, not just the ones actually carrying that
// supplier. Hides the rest here too, same precedence (page filter wins,
// falls back to the in-sheet quick-stock-in picker) as editVisibleLines()
// already uses for the lines nested under each variant.
function editingColorGroups() {
    const groups = colorGroups(editing.value);
    const supplierId = supplierFilter.value || variantStockInSupplier.value;
    if (!supplierId || !hasSourceLines.value) return groups;

    return groups
        .map((g) => ({ ...g, variants: g.variants.filter((v) => variantHasSupplier(v, Number(supplierId))) }))
        .filter((g) => g.variants.length > 0);
}
function uploadColorPhoto(color, event) {
    const file = event.target.files?.[0];
    if (!file || !editing.value) return;
    router.post(route('app.productVariants.updateColorPhoto', editing.value.id), { color, photo: file }, {
        forceFormData: true, preserveScroll: true,
    });
}
const variantStockInQty = ref({}); // { [variantId]: qty }
// one supplier pick covers the quick +-boxes below it — Phase 6B closed
// the Phase 4 gap where the server accepted supplier_id but no UI sent it
const variantStockInSupplier = ref('');
function stockInVariant(v) {
    const qty = variantStockInQty.value[v.id];
    if (!qty || qty <= 0) return;
    router.post(route('app.productVariants.stockIn', v.id), { qty, supplier_id: variantStockInSupplier.value || null }, {
        preserveScroll: true,
        onSuccess: () => (variantStockInQty.value[v.id] = ''),
    });
}

// --- SEAMAN Phase 6B: the opening-stock grid ("স্টক বসান") ---
const hasOpeningStock = computed(() => features.value.includes('opening_stock'));
const osOpen = ref(false);
const osQty = ref({});   // 'v{id}' or 'n|color|size' or 'plain' → qty
const osCost = ref({});  // same keys → optional per-cell cost override
const osPrice = ref({}); // same keys → optional per-cell sell-price override
const osSupplier = ref('');
// Khaled live: "koto tk pay korche ... koto due ache show hobe" — one
// choice for the whole grid submit (one supplier, one visit), same 3-way
// choice the Purchases screen and the other stock-in sheets now share.
const osPaymentMethod = ref('cash');
const osDefaultCost = ref('');
// Khaled live: stock came in from a new supplier at a new sell price, but
// this grid only ever let him price a brand-new cell, never an EXISTING
// one — the number he typed had nowhere to go and the article kept
// showing its old price. Same "default for all cells, a cell's own box
// overrides it" shape as cost, right next to it.
const osDefaultPrice = ref('');
const osNewColors = ref([]);
const osNewSizes = ref([]);
const osNewColorInput = ref('');
const osNewSizeInput = ref('');
const osSaving = ref(false);
const osVariantMap = computed(() => {
    const map = {};
    (editing.value?.variants || []).forEach((v) => { map[`${v.color ?? ''}|${v.size ?? ''}`] = v; });
    return map;
});
const osColors = computed(() => {
    const set = [];
    (editing.value?.variants || []).forEach((v) => { const c = v.color ?? ''; if (!set.includes(c)) set.push(c); });
    osNewColors.value.forEach((c) => { if (!set.includes(c)) set.push(c); });
    return set.length ? set : [''];
});
const osSizes = computed(() => {
    const set = [];
    (editing.value?.variants || []).forEach((v) => { const s = v.size ?? ''; if (!set.includes(s)) set.push(s); });
    osNewSizes.value.forEach((s) => { if (!set.includes(s)) set.push(s); });
    return set.length ? set : [''];
});
function openOpeningStock() {
    osQty.value = {}; osCost.value = {}; osPrice.value = {};
    osSupplier.value = ''; osPaymentMethod.value = 'cash'; osDefaultCost.value = editing.value?.cost ?? '';
    // blank, not pre-filled like cost — a stock-in always HAS a cost, but
    // price should only change when the owner actually types a new one
    osDefaultPrice.value = '';
    osNewColors.value = []; osNewSizes.value = [];
    osNewColorInput.value = ''; osNewSizeInput.value = '';
    osOpen.value = true;
}
function osKey(c, s) {
    const v = osVariantMap.value[`${c}|${s}`];
    return v ? `v${v.id}` : `n|${c}|${s}`;
}
function osAddColor() {
    const c = osNewColorInput.value.trim();
    if (c && !osColors.value.includes(c)) osNewColors.value.push(c);
    osNewColorInput.value = '';
}
function osAddSize() {
    const s = osNewSizeInput.value.trim();
    if (s && !osSizes.value.includes(s)) osNewSizes.value.push(s);
    osNewSizeInput.value = '';
}
function submitOpeningStock() {
    const cells = [];
    const hasVariants = (editing.value?.variants || []).length > 0 || osNewColors.value.length || osNewSizes.value.length;
    if (!hasVariants) {
        const qty = Number(osQty.value.plain);
        if (!qty || qty <= 0) return;
        cells.push({
            qty,
            cost: osCost.value.plain !== undefined && osCost.value.plain !== '' ? Number(osCost.value.plain) : null,
            price: osPrice.value.plain !== undefined && osPrice.value.plain !== '' ? Number(osPrice.value.plain) : null,
        });
    } else {
        for (const c of osColors.value) {
            for (const s of osSizes.value) {
                const key = osKey(c, s);
                const raw = osQty.value[key];
                // empty cell = unchanged, NEVER zero (v-model leaves '')
                if (raw === undefined || raw === '' || Number(raw) <= 0) continue;
                const v = osVariantMap.value[`${c}|${s}`];
                const cost = osCost.value[key] !== undefined && osCost.value[key] !== '' ? Number(osCost.value[key]) : null;
                const price = osPrice.value[key] !== undefined && osPrice.value[key] !== '' ? Number(osPrice.value[key]) : null;
                cells.push(v
                    ? { variant_id: v.id, qty: Number(raw), cost, price }
                    : { color: c || null, size: s || null, qty: Number(raw), cost, price });
            }
        }
    }
    if (!cells.length) { toast('⚠ ' + t('os.nothingFilled')); return; }
    osSaving.value = true;
    router.post(route('app.products.openingStock', editing.value.id), {
        supplier_id: osSupplier.value || null,
        payment_method: osSupplier.value ? osPaymentMethod.value : null,
        default_cost: osDefaultCost.value !== '' ? Number(osDefaultCost.value) : null,
        default_price: osDefaultPrice.value !== '' ? Number(osDefaultPrice.value) : null,
        cells,
    }, {
        preserveScroll: true,
        onSuccess: () => { osOpen.value = false; productSheet.value = false; },
        onFinish: () => (osSaving.value = false),
    });
}

// --- Phase 6B: the fill-in-Excel bulk sheet ---
const bulkOpen = ref(false);
const bulkCategory = ref('');
const bulkSupplier = ref('');
const bulkFile = ref(null);
const bulkPreview = ref(null);
const bulkBusy = ref(false);
const bulkSheetUrl = computed(() => route('app.openingStock.sheet') + (bulkCategory.value ? `?category_id=${bulkCategory.value}` : ''));
function bulkUpload(dryRun) {
    if (!bulkFile.value) { toast('⚠ ' + t('os.pickFile')); return; }
    bulkBusy.value = true;
    const fd = new FormData();
    fd.append('file', bulkFile.value);
    fd.append('dry_run', dryRun ? '1' : '0');
    if (bulkSupplier.value) fd.append('supplier_id', bulkSupplier.value);
    fetch(route('app.openingStock.upload'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
        body: fd,
    })
        .then(async (r) => {
            const data = await r.json();
            if (!r.ok) throw new Error(data.error || `upload responded ${r.status}`);
            if (data.dry_run) {
                bulkPreview.value = data;
            } else {
                toast('✅ ' + t('os.bulkApplied', { n: data.applied }));
                bulkOpen.value = false; bulkPreview.value = null; bulkFile.value = null;
                router.reload({ only: ['products', 'stats'] });
            }
        })
        .catch((e) => { reportClientError(e, 'openingStock bulk'); toast('⚠ ' + (e.message || t('os.bulkFailed'))); })
        .finally(() => (bulkBusy.value = false));
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
const stockInSupplier = ref(''); // Phase 6B — rides into the intake ledger (server accepted it since Phase 4)
// Khaled live: a supplier picked here never affected what's owed to
// them. 'cash'/'bank' settles it on the spot, 'credit' adds to the
// supplier's payable — same 3-way choice the Purchases module already
// uses (More.vue's purchaseForm.method), so it's never a new idea to
// someone who's used that screen. Blank (no choice made) stays a no-op,
// same as before this existed — only shown at all once a supplier is picked.
const stockInPaymentMethod = ref('cash');
const stockInBatchNo = ref('');
const stockInExpiryDate = ref('');
const stockInImeis = ref('');
const stockInWarrantyExpiry = ref('');
const stockInSubmitting = ref(false);
function openStockIn(p) {
    // Khaled live: a plain product under source_lines had TWO different
    // "add stock" buttons (this quick one, and the 🚚 sheet inside the
    // edit sheet) that silently disagreed — this one never wrote a
    // line, so stock added here was invisible to the supplier filter.
    // Routing it straight to the 🚚 sheet leaves exactly one correct way.
    if (hasSourceLines.value) {
        openSourceStockIn('product', p);
        return;
    }
    stockInProduct.value = p;
    stockInQty.value = '';
    stockInCost.value = '';
    stockInSupplier.value = '';
    stockInPaymentMethod.value = 'cash';
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
        qty: stockInQty.value, cost: stockInCost.value, supplier_id: stockInSupplier.value || null,
        payment_method: stockInSupplier.value ? stockInPaymentMethod.value : null,
        batch_no: hasBatchTracking.value ? stockInBatchNo.value : '',
        expiry_date: hasBatchTracking.value ? stockInExpiryDate.value : '',
        imeis: hasSerialTracking.value && stockInProduct.value?.track_serial ? stockInImeis.value : '',
        warranty_expiry: hasSerialTracking.value && stockInProduct.value?.track_serial ? stockInWarrantyExpiry.value : '',
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
const { pollReload, pollOnVisible } = usePollingReload();
let pollTimer = null;
function pollStockNow() {
    if (productSheet.value || stockInSheet.value) return;
    pollReload({ only: ['products'], preserveScroll: true, preserveState: true });
}
onMounted(() => {
    pollTimer = setInterval(pollStockNow, 15000);

    // ধাপ ২.চ (docs/FIX_PLAN.md) — the POS "did you mean... + নতুন পণ্য
    // যোগ করুন" shortcut lands here with the typed, not-found text
    // prefilled, so the owner doesn't have to retype it.
    const newName = new URLSearchParams(window.location.search).get('new_name');
    if (newName) {
        openNew();
        form.name = newName;
    }
});
pollOnVisible(pollStockNow);
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
            <button type="button" class="stat gold" style="width:100%;text-align:left;cursor:pointer" :style="stockStatus === 'low' ? 'border-color:var(--gold);border-width:2px' : ''" @click="filterByStockStatus('low')"><div class="k">{{ t('stock.lowStockCount') }}</div><div class="v">{{ lowStockCount }}</div></button>
            <button type="button" class="stat rose" style="width:100%;text-align:left;cursor:pointer" :style="stockStatus === 'out' ? 'border-color:var(--rose);border-width:2px' : ''" @click="filterByStockStatus('out')"><div class="k">{{ t('stock.outOfStockCount') }}</div><div class="v">{{ outOfStockCount }}</div></button>
            <div v-if="hasBatchTracking" class="stat mint"><div class="k">{{ t('stock.expiringSoonCount') }}</div><div class="v">{{ expiringSoonCount }}</div></div>
        </div>

        <div class="btnrow" style="margin-bottom:12px">
            <button class="btn ghost" style="flex:1" @click="openNew">{{ isRestaurant ? t('stock.addProductRestaurant') : t('stock.addProduct') }}</button>
            <button class="btn ghost" style="flex:1" @click="importSheet = true">{{ t('stock.importCsv') }}</button>
        </div>

        <!-- docs/ARTICLE_SERIES_PLAN.md §7 — Baby Park/Liberty's shoe-article
             entry form; opt-in, only shown once article_series is granted -->
        <Link v-if="features.includes('article_series')" :href="route('app.articles.create')" class="row" style="margin-bottom:12px">
            <div class="ava">👟</div><div class="mid"><b>নতুন আর্টিকেল</b><span>ম্যানুফ্যাকচার + কস্ট + রং×সাইজ গ্রিড — এক স্ক্রিনে</span></div><div class="end">›</div>
        </Link>

        <!-- was previously only reachable as a side-effect of editing a
             specific product's unit dropdown ("or type a new unit") — a
             direct, standalone link here too, right where a shop owner is
             already thinking about their inventory setup -->
        <Link :href="route('app.units.index')" class="row" style="margin-bottom:12px">
            <div class="ava">📏</div><div class="mid"><b>{{ t('unit.title') }}</b><span>{{ t('unit.subtitle') }}</span></div><div class="end">›</div>
        </Link>

        <div style="display:flex;gap:8px;margin-bottom:12px">
            <input
                ref="searchInput" v-model="q" :placeholder="isRestaurant ? t('stock.searchPlaceholderRestaurant') : t('stock.searchPlaceholder')" style="flex:1" @keyup.enter="applyFilter"
                autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
            >
            <button class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openScanner">📷</button>
            <button class="btn sm" style="width:auto;padding:0 16px" @click="applyFilter">{{ t('sales.searchButton') }}</button>
        </div>

        <div v-if="hasPrescriptionRecords && (companies.length || genericNames.length)" class="f2" style="margin-bottom:12px">
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

        <!-- Khaled live: "supplier filter option lagbe" — which products
             came from which supplier, now that a product can carry several
             (Part 1 lines). Matches on the ACTUAL lines for a source_lines
             shop (ProductController::index), or the plain supplier_id
             field otherwise — same filter, same dropdown either way. -->
        <div v-if="suppliers && suppliers.length" class="field" style="margin-bottom:12px">
            <select v-model="supplierFilter" @change="applyFilter">
                <option value="">{{ t('stock.allSuppliers') }}</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </div>

        <!-- Phase 6B — how much of the imported catalog still sits at zero;
             taps straight into the existing out-of-stock filter -->
        <div v-if="hasOpeningStock && outOfStockCount > 0" class="card" style="margin-bottom:10px;padding:10px 12px;display:flex;align-items:center;gap:8px;cursor:pointer" @click="router.get(route('app.stock'), { stock_status: 'out' }, { preserveState: false })">
            <span style="font-size:18px">📦</span>
            <span style="flex:1;font-size:13px">{{ t('os.nilCount', { n: outOfStockCount }) }}</span>
            <span style="color:var(--mut)">›</span>
        </div>

        <div class="tabbar">
            <button :class="{ on: cat === 'all' }" @click="setCategory('all')">{{ t('stock.allCategories') }} · {{ totalProducts }}</button>
            <!-- top-level only; for every shop without sub-categories this
                 list IS the whole list, rendering exactly as before -->
            <button v-for="c in topCategories" :key="c.id" :class="{ on: cat === c.id || childrenOf(c.id).some((s) => s.id === cat) }" @click="setCategory(c.id)">{{ c.emoji }} {{ c.name }} · {{ countWithChildren(c.id) }}</button>
            <button v-if="features.includes('sub_categories')" style="border-style:dashed" @click="router.visit(route('app.categories.index'))">⚙ {{ t('cat.manage') }}</button>
            <button v-if="hasOpeningStock" style="border-style:dashed" @click="bulkOpen = true; bulkPreview = null">📥 {{ t('os.bulkBtn') }}</button>
        </div>
        <!-- SEAMAN Phase 2: a selected parent's kinds, one more tap deep.
             Renders nothing when the category has no children. -->
        <div v-if="subChips.length" class="tabbar" style="margin-top:-8px">
            <button :class="{ on: cat === subParentId }" @click="setCategory(subParentId)">{{ t('cat.allOfParent') }}</button>
            <button v-for="s in subChips" :key="s.id" :class="{ on: cat === s.id }" @click="setCategory(s.id)">{{ s.emoji }} {{ s.name }} · {{ categoryCounts[s.id] || 0 }}</button>
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
                <!-- Phase 4 — "Nil", never blank: the owner's own rule for
                     anything missing. Only with the brands feature. -->
                <span v-if="brands" style="color:var(--mut)">🏭 {{ p.brand?.name || 'Nil' }} • 🚚 {{ productSupplierSummary(p) }}</span>
                <!-- Phase 6 — the importer's VERIFY remarks ride on the
                     product until the owner fixes and clears them -->
                <span v-if="p.internal_note" style="color:var(--amber,#b45309);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">📝 {{ p.internal_note.split('\n')[0] }}</span>
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
                <!-- Khaled: "stock e ekta kore barcode button dite jeta
                     te click korle oi serial barcode lebel page e nia
                     jabe jekhan tekhe print dibe" — straight from the
                     article to its own stickers, already ticked, so the
                     only thing left is to press print. -->
                <Link
                    v-if="hasPerm('barcode_labels') && features.includes('barcode_printing')"
                    :href="route('app.barcodeLabels.index', { product: p.id })"
                    class="btn sm ghost" style="margin-top:6px;text-decoration:none" :title="t('stock.printLabels')"
                    @click.stop
                >🏷</Link>
            </div>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">📦</div>{{ isRestaurant ? t('stock.noProductsRestaurant') : t('stock.noProducts') }}</div>
        <Pagination :links="products.links" />

        <Sheet v-model="productSheet" :title="editing ? (isRestaurant ? t('stock.editTitleRestaurant') : t('stock.editTitle')) : (isRestaurant ? t('stock.newTitleRestaurant') : t('stock.newTitle'))">
            <!-- Root-caused live (2026-09-23, real-user report): a Super
                 Shop owner (batch_tracking is granted to supershop/cosmetics
                 too, for expiry-dated perishables — see config/
                 business_types.php) saw "Search the medicine database" and
                 a Paracetamol-example generic-name field on every new
                 product, looking exactly like the shop had silently become
                 a Pharmacy. These three fields (medicine catalog search,
                 generic name, company) are the pharmacy-only trio — see the
                 💊-prefixed display of generic_name/company on this same
                 page's product rows — batch_tracking alone was never the
                 right gate for them; prescription_records already exists
                 and is pharmacy-exclusive (the requires_prescription
                 checkbox below already correctly uses it). shelf_location
                 stays on hasBatchTracking below — genuinely useful for any
                 shop with a physical rack/shelf system, not pharmacy-only. -->
            <!-- see docs/FIX_PLAN.md's "শেয়ার্ড ক্যাটালগ" design note — a
                 linked branch copy's shared fields (name/price/category/
                 etc.) are owned by the main shop's own product now; the
                 backend already silently ignores any change to them sent
                 from here (defense in depth), this banner just makes that
                 honest instead of surprising -->
            <div v-if="editing?.master_product_id" class="card" style="margin-bottom:14px;background:var(--goldSoft);font-size:12.5px">
                🔗 {{ t('stock.sharedCatalogNote') }}
            </div>

            <button v-if="!editing" type="button" class="btn ghost sm" style="width:100%;margin-bottom:14px" @click="smartScanOpen = true">
                📷 {{ t('smartScan.buttonLabel') }}
            </button>
            <div v-if="!editing && hasPrescriptionRecords" class="field">
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
            <div v-if="hasPrescriptionRecords" class="field">
                <label>{{ t('stock.genericName') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.genericNameHint') }}</span></label>
                <input v-model="form.generic_name" :placeholder="t('stock.genericNamePlaceholder')" list="generic-name-options">
                <datalist id="generic-name-options"><option v-for="g in genericNames" :key="g" :value="g" /></datalist>
                <div v-if="form.errors.generic_name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.generic_name }}</div>
            </div>
            <div v-if="hasPrescriptionRecords" class="field">
                <label>{{ t('stock.company') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.company" :placeholder="t('stock.companyPlaceholder')" list="company-options">
                <datalist id="company-options"><option v-for="c in companies" :key="c" :value="c" /></datalist>
                <!-- Khaled live: "color size eshb add kora aro easy koro
                     ekbar korle pore jate use kora jai" — every colour/size
                     this shop has ever typed, suggested wherever a new one
                     is typed (osNewColorInput/osNewSizeInput, the single
                     add-variant form below) -->
                <datalist id="known-colors"><option v-for="c in knownColors" :key="c" :value="c" /></datalist>
                <datalist id="known-sizes"><option v-for="s in knownSizes" :key="s" :value="s" /></datalist>
                <div v-if="form.errors.company" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.company }}</div>
            </div>
            <div v-if="hasBatchTracking" class="field">
                <label>{{ t('stock.shelfLocation') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.shelf_location" :placeholder="t('stock.shelfLocationPlaceholder')">
                <div v-if="form.errors.shelf_location" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.shelf_location }}</div>
            </div>
            <label v-if="hasPrescriptionRecords" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:13.5px;font-weight:600;cursor:pointer">
                <input v-model="form.requires_prescription" type="checkbox">
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
                    <!-- parents first, each one's sub-categories indented
                         under it — flat shops see the identical flat list -->
                    <template v-for="c in topCategories" :key="c.id">
                        <option :value="c.id">{{ c.emoji }} {{ c.name }}</option>
                        <option v-for="s in childrenOf(c.id)" :key="s.id" :value="s.id">&nbsp;&nbsp;— {{ s.emoji }} {{ s.name }}</option>
                    </template>
                </select>
            </div>

            <!-- Phase 4 — only rendered when the shop has the brands
                 feature (prop is null otherwise) -->
            <div v-if="brands" class="field">
                <label style="display:flex;justify-content:space-between;align-items:center">
                    {{ t('brand.fieldLabel') }}
                    <Link :href="route('app.brands.index')" style="font-size:11.5px;color:var(--brand)">⚙ {{ t('brand.manage') }}</Link>
                </label>
                <select v-model="form.brand_id">
                    <option :value="''">Nil — {{ t('brand.noBrand') }}</option>
                    <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <div v-if="form.errors.category_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.category_id }}</div>
            </div>
            <!-- Seaman live report: the sheet SHOWED 🚚 but nothing set it —
                 this saves with the same সংরক্ষণ button as every field -->
            <div v-if="suppliers" class="field">
                <label>{{ t('os.productSupplier') }}</label>
                <select v-model="form.supplier_id">
                    <option :value="''">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
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
                    <input v-model="form.sold_by_weight" type="checkbox">
                    {{ t('stock.soldByWeight') }}
                </label>
                <div style="color:var(--dim);font-size:12px;margin-top:4px">{{ t('stock.soldByWeightHint') }}</div>
                <div v-if="form.sold_by_weight" class="seg" style="margin-top:8px">
                    <button type="button" :class="{ on: form.weight_unit === 'kg' }" @click="form.weight_unit = 'kg'">{{ t('stock.unitKg') }}</button>
                    <button type="button" :class="{ on: form.weight_unit === 'litre' }" @click="form.weight_unit = 'litre'">{{ t('stock.unitLitre') }}</button>
                    <button type="button" :class="{ on: form.weight_unit === 'meter' }" @click="form.weight_unit = 'meter'">{{ t('stock.unitMeter') }}</button>
                </div>
            </div>

            <div v-if="hasSerialTracking" class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input v-model="form.track_serial" type="checkbox">
                    {{ t('stock.trackSerial') }}
                </label>
                <div style="color:var(--dim);font-size:12px;margin-top:4px">{{ t('stock.trackSerialHint') }}</div>
            </div>

            <div class="f2">
                <div class="field">
                    <label>{{ form.sold_by_weight ? t('stock.sellPricePerUnit', { unit: weightUnitLabel(form.weight_unit) }) : t('stock.sellPrice') }}</label>
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
            <div v-if="hasWholesaleTiers && !form.sold_by_weight" class="field">
                <label>{{ t('stock.wholesaleMoq') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.wholesaleMoqHint') }}</span></label>
                <input v-model="form.wholesale_moq" type="number" min="1" step="1">
                <div v-if="form.errors.wholesale_moq" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.wholesale_moq }}</div>
            </div>
            <div v-if="hasWholesaleTiers && !form.sold_by_weight" class="field">
                <label>{{ t('stock.priceTiers') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.priceTiersHint') }}</span></label>
                <div v-for="(tier, i) in form.price_tiers" :key="i" style="display:flex;gap:8px;align-items:center;margin-top:6px">
                    <input v-model="tier.min_qty" type="number" min="1" step="1" :placeholder="t('stock.minQty')" style="flex:1">
                    <input v-model="tier.price" type="number" min="0" step="0.01" :placeholder="t('stock.tierPrice')" style="flex:1">
                    <button type="button" class="btn sm rose" @click="removeTier(i)" style="flex:0 0 auto">✕</button>
                </div>
                <button type="button" class="btn ghost sm" style="width:100%;margin-top:8px" @click="addTier">+ {{ t('stock.addTier') }}</button>
                <div v-if="form.errors['price_tiers']" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors['price_tiers'] }}</div>
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
                        <input v-model="form.available" type="checkbox">
                        {{ form.available ? t('pos.availableToday') : t('pos.soldOutToday') }}
                    </label>
                </div>
                <div class="field" v-else-if="!(isRestaurant && form.stock_mode === 'untracked')">
                    <label>{{ t('stock.stock') }}{{ form.sold_by_weight ? ` (${weightUnitLabel(form.weight_unit)})` : '' }}</label>
                    <input v-model="form.stock" type="number" :step="form.sold_by_weight ? 0.001 : 1">
                    <div v-if="form.errors.stock" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.stock }}</div>
                </div>
                <div class="field"><label>{{ t('stock.barcode') }}</label><input v-model="form.barcode"></div>
            </div>

            <!-- Part 1 — a plain (no colour/size) product's own supplier/brand lots -->
            <div v-if="editing && !editingHasVariants && hasSourceLines" class="field">
                <label>{{ t('os.sourcesLabel') }}</label>
                <div v-for="line in editVisibleLines(editing)" :key="line.id" style="font-size:12px;color:var(--mut);padding:3px 0;display:flex;align-items:center;gap:6px">
                    <span style="flex:1">🚚 {{ line.supplier?.name || 'Nil' }}<template v-if="line.brand?.name"> · {{ line.brand.name }}</template>
                    · {{ t('stock.costShort') }} {{ money(line.cost) }} · {{ money(line.price) }} · {{ line.stock }} {{ t('stock.pieces') }}</span>
                    <button class="btn sm ghost" style="padding:2px 8px;font-size:11px;white-space:nowrap" @click="openEditSource(line, editing.name)">{{ t('os.editPriceBtn') }}</button>
                    <button v-if="Number(line.stock) > 0" class="btn sm ghost" style="padding:2px 8px;font-size:11px;white-space:nowrap" @click="openMoveLine(line, editing.name)">{{ t('os.moveBtn') }}</button>
                    <button class="btn sm rose" style="padding:2px 8px;font-size:11px" @click="deleteSource(line)">{{ t('os.deleteLineBtn') }}</button>
                </div>
                <div v-if="!(editing.sources || []).length" style="font-size:12px;color:var(--mut)">{{ t('os.noSourcesYet') }}</div>
                <div v-if="untrackedQty(editing) > 0" style="font-size:12px;color:var(--gold2);margin-top:4px">
                    ⚠ {{ t('os.untracked', { n: untrackedQty(editing) }) }}
                    <button class="btn sm ghost" style="padding:2px 8px;font-size:11px;margin-left:6px" @click="openClaim('product', editing, editing.name)">{{ t('os.claimBtn') }}</button>
                </div>
                <button class="btn sm ghost" style="margin-top:6px;width:auto" @click="openSourceStockIn('product', editing)">🚚 {{ t('os.addSourceStock') }}</button>
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

            <div v-if="!editing && hasUnitConversion" class="field" style="background:var(--panel2);border-radius:var(--radius-sm);padding:10px 12px;font-size:12.5px;color:var(--mut)">
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
            <div v-if="editing && hasProductVariants && preExistingUnallocatedStock > 0" class="card" style="background:var(--goldSoft);border-color:var(--gold2);margin-bottom:10px">
                <b>⚠ {{ t('stock.preExistingStockTitle') }}</b>
                <div style="font-size:13px;margin-top:4px">{{ t('stock.preExistingStockHint', { qty: preExistingUnallocatedStock }) }}</div>
            </div>
            <!-- "kon supplier koi ta product" — whose goods this product
                 holds, totalled across every variant's lots, with that
                 supplier's stickers one tap away -->
            <div v-if="editing && hasSourceLines && supplierBreakdown(editing).length" class="card" style="margin-bottom:10px;padding:10px">
                <b style="font-size:13px">🚚 {{ t('os.breakdownTitle') }}</b>
                <div v-for="s in supplierBreakdown(editing)" :key="s.id ?? 'nil'" style="display:flex;align-items:center;gap:8px;font-size:12.5px;padding:4px 0;border-bottom:1px dashed var(--line)">
                    <span style="flex:1"><b>{{ s.name }}</b> — {{ s.qty }} {{ t('stock.pieces') }}</span>
                    <Link :href="route('app.barcodeLabels.index') + '?product=' + editing.id + (s.id ? '&supplier=' + s.id : '')" class="btn sm ghost" style="text-decoration:none;width:auto;padding:4px 10px">🏷 {{ t('os.printStickers') }}</Link>
                </div>
                <div v-if="productUntracked(editing) > 0" style="font-size:12px;color:var(--gold2);padding-top:6px">
                    ⚠ {{ t('os.untracked', { n: productUntracked(editing) }) }} — {{ t('os.untrackedWhere') }}
                </div>
            </div>
            <!-- Phase 6B — the opening-stock grid: the fast way to put real
                 counts on an imported (all-zero) product -->
            <button v-if="editing && hasOpeningStock" class="btn sm" style="width:100%;margin-bottom:10px" @click="openOpeningStock">
                📦 {{ t('os.openBtn') }}
            </button>
            <div v-if="editing && hasProductVariants && suppliers && !osOpen" class="field">
                <label>{{ t('os.quickSupplier') }}</label>
                <select v-model="variantStockInSupplier">
                    <option value="">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div v-if="editing && hasProductVariants" class="field">
                <label>{{ t('stock.variants') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.variantsHint') }}</span></label>
                <button v-if="!gridOpen" class="btn sm" style="width:100%;margin-top:10px" @click="openGrid">
                    {{ t('stock.addVariantGrid') }}
                </button>

                <div v-if="gridOpen" style="margin-top:10px;padding:10px;border:1px dashed var(--line);border-radius:var(--radius-sm)">
                    <div style="font-size:12px;color:var(--mut);margin-bottom:8px">{{ t('stock.gridHint') }}</div>
                    <!-- whose goods these are — starts on the product's
                         default, changeable per delivery ("kar nam e uthb") -->
                    <div v-if="suppliers" class="field" style="margin-bottom:8px">
                        <label>{{ t('os.supplier') }}</label>
                        <select v-model="gridSupplier">
                            <option value="">Nil — {{ t('os.noSupplier') }}</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.count }})</option>
                        </select>
                    </div>
                    <div v-if="brands" class="field" style="margin-bottom:8px">
                        <label>{{ t('brand.fieldLabel') }}</label>
                        <select v-model="gridBrand">
                            <option value="">Nil — {{ t('brand.noBrand') }}</option>
                            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div v-if="suppliers && gridSupplier" class="field" style="margin-bottom:8px">
                        <label>{{ t('purchase.method') }}</label>
                        <select v-model="gridPaymentMethod">
                            <option value="cash">{{ t('pay.cash') }}</option>
                            <option value="bank">{{ t('purchase.methodBank') }}</option>
                            <option value="credit">{{ t('pay.credit') }}</option>
                        </select>
                    </div>
                    <div class="f2">
                        <input v-model="gridColorsInput" :placeholder="editing?.option1_label ? opt1Label + ' — ' + t('stock.commaSeparated') : t('stock.gridColorsPlaceholder')">
                        <input v-model="gridSizesInput" :placeholder="editing?.option2_label ? opt2Label + ' — ' + t('stock.commaSeparated') : t('stock.gridSizesPlaceholder')">
                    </div>

                    <div v-if="gridColors.length || gridSizes.length" style="overflow-x:auto;margin-top:10px">
                        <table style="border-collapse:collapse;width:100%">
                            <thead>
                                <tr>
                                    <th style="text-align:left;font-size:11px;color:var(--dim);padding:4px 6px"></th>
                                    <th v-for="size in (gridSizes.length ? gridSizes : [''])" :key="'h-' + size" style="font-size:12px;padding:4px 6px;text-align:center">{{ size ? formatSize(size) : opt2Label }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="color in (gridColors.length ? gridColors : [''])" :key="'r-' + color">
                                    <td style="font-size:12px;padding:4px 6px;white-space:nowrap">{{ color || opt1Label }}</td>
                                    <td v-for="size in (gridSizes.length ? gridSizes : [''])" :key="'c-' + color + size" style="padding:3px">
                                        <input
                                            v-model="gridStock[gridCellKey(color, size)]"
                                            type="number" min="0" placeholder="0"
                                            style="width:56px;text-align:center;margin:0;padding:6px 4px"
                                        >
                                        <!-- the cell's OWN numbers, only in per-cell mode (SEAMAN Phase 3) -->
                                        <template v-if="gridPerCell">
                                            <input v-model="gridCellCost[gridCellKey(color, size)]" type="number" min="0" :placeholder="t('stock.costShort')" style="width:56px;text-align:center;margin:3px 0 0;padding:4px;font-size:11px">
                                            <input v-model="gridCellPrice[gridCellKey(color, size)]" type="number" min="0" :placeholder="t('stock.priceShort')" style="width:56px;text-align:center;margin:3px 0 0;padding:4px;font-size:11px">
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Khaled: a size added later had nowhere to put its
                         buying and selling price, so it silently inherited
                         the article's headline one. Blank still inherits —
                         that is the old behaviour, now a choice. -->
                    <label v-if="hasVariantOptions" style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12.5px;cursor:pointer">
                        <input v-model="gridPerCell" type="checkbox">
                        {{ t('stock.perCellPrices') }}
                    </label>

                    <div style="margin-top:10px">
                        <div style="font-size:12px;color:var(--mut);margin-bottom:6px">{{ t('stock.gridPriceHint') }}</div>
                        <template v-if="editingUsesSizeGroups">
                            <div v-for="g in kidsShoeSizeGroups" :key="g.label" style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
                                <div style="flex:0 0 96px;font-size:12px">
                                    <b>{{ g.label }}</b>
                                    <div style="color:var(--dim);font-size:11px">{{ g.min_size }}–{{ g.max_size }}</div>
                                </div>
                                <input v-model="gridGroupCosts[g.label]" type="number" min="0" :placeholder="t('stock.variantCostOptional')" style="flex:1;margin:0">
                                <input v-model="gridGroupPrices[g.label]" type="number" min="0" :placeholder="t('stock.variantPriceOptional')" style="flex:1;margin:0">
                            </div>
                        </template>
                        <div v-else class="f2">
                            <input v-model="gridCost" type="number" min="0" :placeholder="t('stock.variantCostOptional')">
                            <input v-model="gridPrice" type="number" min="0" :placeholder="t('stock.variantPriceOptional')">
                        </div>
                    </div>

                    <template v-if="preExistingUnallocatedStock > 0">
                        <div style="font-size:12px;margin-top:8px" :style="{ color: gridTotal === preExistingUnallocatedStock ? 'var(--green)' : 'var(--mut)' }">
                            {{ t('stock.allocatedOf', { allocated: gridTotal, total: preExistingUnallocatedStock }) }}
                        </div>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px">
                            <input v-model="gridWriteOffRest" type="checkbox">
                            {{ t('stock.writeOffRestLabel') }}
                        </label>
                    </template>
                    <div v-if="gridError" style="color:var(--rose);font-size:12px;margin-top:8px">{{ gridError }}</div>
                    <div style="display:flex;gap:8px;margin-top:10px">
                        <button class="btn sm" style="flex:1" :disabled="gridProcessing" @click="submitGrid">{{ gridProcessing ? '...' : t('stock.gridSubmit') }}</button>
                        <button class="btn sm ghost" @click="gridOpen = false">{{ t('common.cancel') }}</button>
                    </div>
                </div>

                <div v-for="g in editingColorGroups()" :key="g.color ?? '_'" style="margin-bottom:10px">
                    <div v-if="g.color" style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                        <img v-if="g.photoUrl" :src="g.photoUrl" style="width:30px;height:30px;border-radius:6px;object-fit:cover">
                        <div v-else style="width:30px;height:30px;border-radius:6px;background:var(--panel2)"></div>
                        <b style="font-size:13px">{{ g.color }}</b>
                        <label style="font-size:11px;color:var(--sky);cursor:pointer">
                            {{ g.photoUrl ? 'ছবি বদলান' : '📷 ছবি দিন' }}
                            <input type="file" accept="image/*" style="display:none" @change="uploadColorPhoto(g.color, $event)">
                        </label>
                    </div>
                    <div v-for="v in g.variants" :key="v.id" class="cart-line" style="align-items:flex-start">
                        <div class="nm">
                            <b>{{ variantLabel(v) }}</b>
                            <!-- exactly which size/color needs restocking — Khaled: "low stock e click korle jeno deka jai konta konta low" -->
                            <span :style="Number(v.stock) <= 0 ? 'color:var(--rose);font-weight:700' : (Number(v.reorder_point) > 0 && Number(v.stock) <= Number(v.reorder_point) ? 'color:var(--gold2);font-weight:700' : '')">
                                {{ v.stock }} {{ t('stock.pieces') }}
                                <template v-if="Number(v.stock) <= 0"> — {{ t('stock.outOfStock') }}</template>
                                <template v-else-if="Number(v.reorder_point) > 0 && Number(v.stock) <= Number(v.reorder_point) "> ⚠ {{ t('stock.lowStock') }}</template>
                            </span>
                            <span><template v-if="v.price"> • {{ money(v.price) }}</template><template v-if="v.barcode"> • 🏷 {{ v.barcode }}</template><template v-if="v.reorder_point"> • reorder: {{ v.reorder_point }}</template></span>
                            <!-- Phase 4 — per-variant brand (helmet: four brands in one product); only with the feature -->
                            <select v-if="brands" :value="v.brand_id || ''" style="margin-top:6px;font-size:12px;padding:5px 8px;width:auto" @change="setVariantBrand(v, $event.target.value)">
                                <option value="">Brand: Nil</option>
                                <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <!-- Part 1 — "one product, many sources": each
                                 supplier/brand lot's own buy/sell/stock,
                                 under this exact variant -->
                            <div v-if="hasSourceLines && editVisibleLines(v).length" style="margin-top:6px;border-top:1px dashed var(--line);padding-top:6px">
                                <div v-for="line in editVisibleLines(v)" :key="line.id" style="font-size:11.5px;color:var(--mut);padding:2px 0;display:flex;align-items:center;gap:6px">
                                    <span style="flex:1">🚚 {{ line.supplier?.name || 'Nil' }}<template v-if="line.brand?.name"> · {{ line.brand.name }}</template>
                                    · {{ t('stock.costShort') }} {{ money(line.cost) }} · {{ money(line.price) }} · {{ line.stock }} {{ t('stock.pieces') }}</span>
                                    <button class="btn sm ghost" style="padding:2px 8px;font-size:11px;white-space:nowrap" @click="openEditSource(line, variantLabel(v))">{{ t('os.editPriceBtn') }}</button>
                                    <button v-if="Number(line.stock) > 0" class="btn sm ghost" style="padding:2px 8px;font-size:11px;white-space:nowrap" @click="openMoveLine(line, variantLabel(v))">{{ t('os.moveBtn') }}</button>
                                    <button class="btn sm rose" style="padding:2px 8px;font-size:11px" @click="deleteSource(line)">{{ t('os.deleteLineBtn') }}</button>
                                </div>
                            </div>
                            <div v-if="hasSourceLines && untrackedQty(v) > 0" style="font-size:11.5px;color:var(--gold2);margin-top:4px">
                                ⚠ {{ t('os.untracked', { n: untrackedQty(v) }) }}
                                <button class="btn sm ghost" style="padding:2px 8px;font-size:11px;margin-left:6px" @click="openClaim('variant', v, variantLabel(v))">{{ t('os.claimBtn') }}</button>
                            </div>
                            <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
                                <input v-model="variantStockInQty[v.id]" type="number" :placeholder="t('stock.stockIn')" style="width:90px">
                                <button class="btn sm ghost" @click="stockInVariant(v)">+</button>
                                <button v-if="Number(v.stock) > 0" class="btn sm ghost" @click="openDecrease(v)">{{ t('stock.decreaseBtn') }}</button>
                                <!-- new stock just landed here — printing ITS label shouldn't need navigating away, re-searching for the product by hand, and finding this one size among every other size. Lands pre-ticked on just this variant. -->
                                <Link :href="route('app.barcodeLabels.index') + '?product=' + editing.id + '&variant=' + v.id" class="btn sm ghost" style="text-decoration:none">🏷</Link>
                            </div>
                            <div style="display:flex;gap:10px;margin-top:4px">
                                <button class="btn sm ghost" style="width:auto;padding:2px 0;border:none;background:none;color:var(--sky);font-size:11px" @click="openExact(v)">{{ t('stock.setExactBtn') }}</button>
                                <button class="btn sm ghost" style="width:auto;padding:2px 0;border:none;background:none;color:var(--mut);font-size:11px" @click="openHistory(v)">{{ t('stock.historyBtn') }}</button>
                            </div>
                            <button v-if="hasSourceLines" class="btn sm ghost" style="margin-top:6px;width:auto" @click="openSourceStockIn('variant', v)">🚚 {{ t('os.addSourceStock') }}</button>
                        </div>
                        <button class="btn sm rose" @click="removeVariant(v)">✕</button>
                    </div>
                </div>

                <!-- SEAMAN Phase 3: what THIS product calls its two axes —
                     epaulettes say Rank × Type where shoes say রং × সাইজ.
                     Saved with the product (সংরক্ষণ button below); every
                     grid/label/picker then speaks in those words. -->
                <template v-if="hasVariantOptions">
                    <div class="hr"></div>
                    <div style="font-size:12px;color:var(--dim);margin-bottom:6px">{{ t('stock.optionLabelsHint') }}</div>
                    <div class="f2">
                        <input v-model="form.option1_label" :placeholder="t('stock.option1Placeholder')">
                        <input v-model="form.option2_label" :placeholder="t('stock.option2Placeholder')">
                    </div>
                </template>

                <!-- Phase 6 — importer's VERIFY note: fix the product, then
                     empty this box and save to clear the 📝 badge -->
                <template v-if="form.internal_note || editing?.internal_note">
                    <div class="hr"></div>
                    <div style="font-size:12px;color:var(--dim);margin-bottom:6px">{{ t('stock.internalNote') }}</div>
                    <textarea v-model="form.internal_note" rows="3" style="width:100%"></textarea>
                </template>

                <div class="hr"></div>
                <div style="font-size:12px;color:var(--dim);margin-bottom:6px">{{ t('stock.addVariantSingle') }}</div>
                <div class="f2">
                    <input v-model="variantForm.size" list="known-sizes" :placeholder="opt2Label">
                    <input v-model="variantForm.color" list="known-colors" :placeholder="opt1Label">
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="variantForm.stock" type="number" :placeholder="t('stock.startingStock')">
                    <input v-model="variantForm.cost" type="number" min="0" :placeholder="t('stock.variantCostOptional')">
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="variantForm.price" type="number" :placeholder="t('stock.variantPriceOptional')">
                    <input v-model="variantForm.reorder_point" type="number" min="0" :placeholder="t('stock.variantReorderOptional')">
                </div>
                <div class="f2" style="margin-top:8px">
                    <input v-model="variantForm.barcode" :placeholder="t('stock.variantBarcodeAutoHint')">
                </div>
                <label v-if="preExistingUnallocatedStock > 0" style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px">
                    <input v-model="variantForm.write_off_rest" type="checkbox">
                    {{ t('stock.writeOffRestLabel') }}
                </label>
                <div v-if="variantForm.errors.size || variantForm.errors.stock" style="color:var(--rose);font-size:12px;margin-top:6px">{{ variantForm.errors.size || variantForm.errors.stock }}</div>
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
            <div v-if="stockInProduct?.product_units?.length" class="field" style="background:var(--panel2);border-radius:var(--radius-sm);padding:8px 10px;font-size:12px;color:var(--mut)">
                {{ t('stock.stockInPacksHint', { pieces: unitLabel(stockInProduct) }) }}
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('stock.howManyArrived') }} ({{ unitLabel(stockInProduct) }})</label><input v-model="stockInQty" type="number" :step="stockInProduct?.sold_by_weight ? 0.001 : 1"></div>
                <div class="field"><label>{{ t('stock.costOptional') }}</label><input v-model="stockInCost" type="number" step="0.01"></div>
            </div>
            <!-- Phase 6B — rides into the intake ledger; "Nil" allowed -->
            <div v-if="suppliers" class="field">
                <label>{{ t('os.supplier') }}</label>
                <select v-model="stockInSupplier">
                    <option value="">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.count }})</option>
                </select>
            </div>
            <!-- Khaled live: "koto tk pay korche add korte parbe koto due
                 ache show hobe" — only asked once a supplier is actually
                 picked (Nil has no one to owe); same 3-way choice the
                 Purchases screen already uses -->
            <div v-if="stockInSupplier" class="field">
                <label>{{ t('purchase.method') }}</label>
                <select v-model="stockInPaymentMethod">
                    <option value="cash">{{ t('pay.cash') }}</option>
                    <option value="bank">{{ t('purchase.methodBank') }}</option>
                    <option value="credit">{{ t('pay.credit') }}</option>
                </select>
            </div>
            <template v-if="hasBatchTracking">
                <div class="field"><label>{{ t('stock.batchNo') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="stockInBatchNo"></div>
                <!-- full width, not paired in .f2 like batchNo above it - a
                     native date input's own day/month/year+calendar-icon
                     widget needs more room than a half-width column gives
                     it on a narrow phone (reported: this field visibly
                     breaking during stock-in) -->
                <div class="field"><label>{{ t('stock.expiryDate') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="stockInExpiryDate" type="date"></div>
            </template>
            <template v-if="hasSerialTracking && stockInProduct?.track_serial">
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

        <!-- SEAMAN Phase 6B — the opening-stock grid: colours as rows,
             sizes/options as columns, one box per real variant; an empty
             box is UNCHANGED, never zero -->
        <Sheet v-model="osOpen" :title="'📦 ' + t('os.openBtn') + (editing ? ' — ' + editing.name : '')">
            <HowToHint :text="t('os.howTo')" />
            <div class="f2">
                <div v-if="suppliers" class="field">
                    <label>{{ t('os.supplier') }}</label>
                    <select v-model="osSupplier">
                        <option value="">Nil — {{ t('os.noSupplier') }}</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.count }})</option>
                    </select>
                </div>
                <div class="field"><label>{{ t('os.defaultCost') }}</label><input v-model="osDefaultCost" type="number" step="0.01"></div>
                <div class="field"><label>{{ t('os.defaultPrice') }}</label><input v-model="osDefaultPrice" type="number" step="0.01"></div>
                <!-- Khaled live: "koto tk pay korche ... koto due ache
                     show hobe" — one choice for the whole submit -->
                <div v-if="osSupplier" class="field">
                    <label>{{ t('purchase.method') }}</label>
                    <select v-model="osPaymentMethod">
                        <option value="cash">{{ t('pay.cash') }}</option>
                        <option value="bank">{{ t('purchase.methodBank') }}</option>
                        <option value="credit">{{ t('pay.credit') }}</option>
                    </select>
                </div>
            </div>

            <template v-if="(editing?.variants || []).length || osNewColors.length || osNewSizes.length">
                <div style="overflow-x:auto;margin-bottom:10px">
                    <table style="border-collapse:collapse;font-size:12px;min-width:100%">
                        <thead>
                            <tr>
                                <th style="padding:4px 6px;text-align:left;color:var(--mut)">{{ opt1Label }} \ {{ opt2Label }}</th>
                                <th v-for="s in osSizes" :key="s" style="padding:4px 6px;color:var(--mut);white-space:nowrap">{{ s === '' ? '—' : formatSize(s) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in osColors" :key="c">
                                <td style="padding:4px 6px;font-weight:600;white-space:nowrap">{{ c === '' ? '—' : c }}</td>
                                <td v-for="s in osSizes" :key="s" style="padding:3px">
                                    <template v-if="osVariantMap[`${c}|${s}`] || osNewColors.includes(c) || osNewSizes.includes(s)">
                                        <input v-model="osQty[osKey(c, s)]" type="number" min="0" :placeholder="osVariantMap[`${c}|${s}`] ? String(0 + osVariantMap[`${c}|${s}`].stock) : t('os.newCell')" style="width:64px;padding:6px" :style="!osVariantMap[`${c}|${s}`] ? 'border-style:dashed' : ''">
                                        <input v-model="osCost[osKey(c, s)]" type="number" min="0" :placeholder="t('stock.costShort')" style="width:64px;padding:6px;margin-top:2px;font-size:11px;display:block">
                                        <input v-model="osPrice[osKey(c, s)]" type="number" min="0" :placeholder="t('os.priceShort')" style="width:64px;padding:6px;margin-top:2px;font-size:11px;display:block">
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="font-size:11.5px;color:var(--mut);margin-bottom:8px">{{ t('os.cellHint') }}</div>
                <div class="f2" style="margin-bottom:10px">
                    <div style="display:flex;gap:6px">
                        <input v-model="osNewColorInput" list="known-colors" :placeholder="'+ ' + opt1Label" @keyup.enter="osAddColor">
                        <button class="btn sm ghost" style="width:auto" @click="osAddColor">+</button>
                    </div>
                    <div style="display:flex;gap:6px">
                        <input v-model="osNewSizeInput" list="known-sizes" :placeholder="'+ ' + opt2Label" @keyup.enter="osAddSize">
                        <button class="btn sm ghost" style="width:auto" @click="osAddSize">+</button>
                    </div>
                </div>
            </template>
            <template v-else>
                <div class="f2">
                    <div class="field"><label>{{ t('os.plainQty') }}</label><input v-model="osQty.plain" type="number" min="0"></div>
                    <div class="field"><label>{{ t('stock.costOptional') }}</label><input v-model="osCost.plain" type="number" min="0"></div>
                    <div class="field"><label>{{ t('os.sellPriceOptional') }}</label><input v-model="osPrice.plain" type="number" min="0"></div>
                </div>
            </template>

            <button class="btn" :disabled="osSaving" @click="submitOpeningStock">{{ osSaving ? '...' : t('os.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="osOpen = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- Part 1 — one screen: supplier(+brand) + qty + buying price.
             Merges into a matching lot or makes a new one; the sell price
             defaults to the last lot's (editable before saving). -->
        <Sheet v-model="sourceSheet" :title="'🚚 ' + t('os.addSourceStock') + (sourceTarget ? ' — ' + sourceTarget.label : '')">
            <HowToHint :text="t('os.sourcesHowTo')" />
            <div v-if="sourceTarget?.lines?.length" class="card" style="margin-bottom:12px;padding:8px 10px">
                <div style="font-size:11.5px;color:var(--mut);margin-bottom:4px">{{ t('os.existingLines') }}</div>
                <div v-for="line in sourceTarget.lines" :key="line.id" style="font-size:12px;padding:2px 0">
                    🚚 {{ line.supplier?.name || 'Nil' }}<template v-if="line.brand?.name"> · {{ line.brand.name }}</template>
                    · {{ t('stock.costShort') }} {{ money(line.cost) }} · {{ money(line.price) }} · {{ line.stock }} {{ t('stock.pieces') }}
                </div>
            </div>
            <div class="field">
                <label>{{ t('os.supplier') }}</label>
                <select v-model="sourceForm.supplier_id">
                    <option value="">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.count }})</option>
                </select>
            </div>
            <div v-if="brands" class="field">
                <label>{{ t('brand.fieldLabel') }}</label>
                <select v-model="sourceForm.brand_id">
                    <option value="">Nil — {{ t('brand.noBrand') }}</option>
                    <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('stock.howManyArrived') }}</label><input v-model="sourceForm.qty" type="number" min="1"></div>
                <div class="field"><label>{{ t('stock.costShort') }} ({{ t('os.paidCost') }})</label><input v-model="sourceForm.cost" type="number" min="0"></div>
            </div>
            <div class="field">
                <label>{{ t('os.sellPriceOptional') }}</label>
                <input v-model="sourceForm.price" type="number" min="0">
            </div>
            <!-- Khaled live: "koto tk pay korche ... koto due ache show hobe" -->
            <div v-if="sourceForm.supplier_id" class="field">
                <label>{{ t('purchase.method') }}</label>
                <select v-model="sourceForm.payment_method">
                    <option value="cash">{{ t('pay.cash') }}</option>
                    <option value="bank">{{ t('purchase.methodBank') }}</option>
                    <option value="credit">{{ t('pay.credit') }}</option>
                </select>
            </div>
            <div v-if="sourceForm.errors.qty || sourceForm.errors.cost" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ sourceForm.errors.qty || sourceForm.errors.cost }}</div>
            <button class="btn" :disabled="sourceForm.processing" @click="saveSourceStockIn">{{ sourceForm.processing ? '...' : t('os.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sourceSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- correction sheet — re-file a wrongly-tagged lot, or claim untracked stock for a supplier; never a stock-in -->
        <Sheet v-model="fixSheet" :title="(fixMode === 'move' ? '↪ ' + t('os.moveTitle') : '⚠ ' + t('os.claimTitle')) + (fixTarget?.label ? ' — ' + fixTarget.label : '')">
            <HowToHint :text="fixMode === 'move' ? t('os.moveHowTo') : t('os.claimHowTo')" />
            <div v-if="fixMode === 'move' && fixTarget?.line" class="card" style="margin-bottom:12px;padding:8px 10px;font-size:12px">
                🚚 {{ fixTarget.line.supplier?.name || 'Nil' }}<template v-if="fixTarget.line.brand?.name"> · {{ fixTarget.line.brand.name }}</template>
                · {{ fixTarget.line.stock }} {{ t('stock.pieces') }}
            </div>
            <div class="field">
                <label>{{ t('os.moveToSupplier') }}</label>
                <select v-model="fixForm.supplier_id">
                    <option value="">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.count }})</option>
                </select>
            </div>
            <div v-if="brands" class="field">
                <label>{{ t('brand.fieldLabel') }}</label>
                <select v-model="fixForm.brand_id">
                    <option value="">Nil — {{ t('brand.noBrand') }}</option>
                    <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
            <div class="field"><label>{{ t('os.howManyPieces') }}</label><input v-model="fixForm.qty" type="number" min="1"></div>
            <div v-if="fixMode === 'claim'" class="f2">
                <div class="field"><label>{{ t('stock.costShort') }}</label><input v-model="fixForm.cost" type="number" min="0"></div>
                <div class="field"><label>{{ t('os.sellPriceOptional') }}</label><input v-model="fixForm.price" type="number" min="0"></div>
            </div>
            <div v-if="fixForm.errors.qty || fixForm.errors.supplier_id" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ fixForm.errors.qty || fixForm.errors.supplier_id }}</div>
            <button class="btn" :disabled="fixForm.processing" @click="saveFix">{{ fixForm.processing ? '...' : t('os.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="fixSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- SEAMAN: fix a mistyped buy/sell price on one supplier's lot -->
        <Sheet v-model="editSourceSheet" :title="'✏️ ' + t('os.editPriceTitle') + (editSourceTarget?.label ? ' — ' + editSourceTarget.label : '')">
            <div v-if="editSourceTarget?.line" class="card" style="margin-bottom:12px;padding:8px 10px;font-size:12px">
                🚚 {{ editSourceTarget.line.supplier?.name || 'Nil' }}<template v-if="editSourceTarget.line.brand?.name"> · {{ editSourceTarget.line.brand.name }}</template>
                · {{ editSourceTarget.line.stock }} {{ t('stock.pieces') }}
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('stock.costShort') }}</label><input v-model="editSourceForm.cost" type="number" min="0"></div>
                <div class="field"><label>{{ t('os.sellPriceOptional') }}</label><input v-model="editSourceForm.price" type="number" min="0"></div>
            </div>
            <div v-if="editSourceForm.errors.cost || editSourceForm.errors.price" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ editSourceForm.errors.cost || editSourceForm.errors.price }}</div>
            <button class="btn" :disabled="editSourceForm.processing" @click="saveEditSource">{{ editSourceForm.processing ? '...' : t('os.editPriceSave') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="editSourceSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- Stock − : take a WRONG qty off a variant, with a reason — never a silent wipe -->
        <Sheet v-model="decreaseSheet" :title="t('stock.decreaseTitle') + (decreaseTarget ? ' — ' + decreaseTarget.label : '')">
            <HowToHint :text="t('stock.decreaseHowTo')" />
            <div class="field"><label>{{ t('os.howManyPieces') }}</label><input v-model="decreaseForm.qty" type="number" min="1"></div>
            <div class="field">
                <label>{{ t('stock.decreaseReason') }}</label>
                <select v-model="decreaseForm.reason">
                    <option value="wrong_entry">{{ t('stock.reasonWrongEntry') }}</option>
                    <option value="damaged">{{ t('stock.reasonDamaged') }}</option>
                    <option value="lost">{{ t('stock.reasonLost') }}</option>
                    <option value="other">{{ t('stock.reasonOther') }}</option>
                </select>
            </div>
            <div v-if="decreaseForm.reason === 'other'" class="field">
                <label>{{ t('stock.decreaseNote') }}</label>
                <input v-model="decreaseForm.note">
            </div>
            <div v-if="decreaseForm.errors.qty || decreaseForm.errors.note" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ decreaseForm.errors.qty || decreaseForm.errors.note }}</div>
            <button class="btn" :disabled="decreaseForm.processing" @click="saveDecrease">{{ decreaseForm.processing ? '...' : t('os.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="decreaseSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- Set exact stock : a recount, not a stock-in/damage event -->
        <Sheet v-model="exactSheet" :title="t('stock.setExactTitle') + (exactTarget ? ' — ' + exactTarget.label : '')">
            <HowToHint :text="t('stock.setExactHowTo')" />
            <div class="field"><label>{{ t('stock.setExactLabel') }}</label><input v-model="exactForm.stock" type="number" min="0"></div>
            <div v-if="exactForm.errors.stock" style="color:var(--rose);font-size:12.5px;margin-bottom:10px">{{ exactForm.errors.stock }}</div>
            <button class="btn" :disabled="exactForm.processing" @click="saveExact">{{ exactForm.processing ? '...' : t('os.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="exactSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <!-- per-variant history — read-only -->
        <Sheet v-model="historySheet" :title="t('stock.historyTitle') + (historyTarget ? ' — ' + historyTarget.label : '')">
            <div v-if="historyLoading" style="text-align:center;color:var(--mut);padding:20px 0">...</div>
            <div v-else-if="!historyRows.length" style="text-align:center;color:var(--mut);padding:20px 0">{{ t('stock.historyEmpty') }}</div>
            <div v-for="r in historyRows" :key="r.id" style="padding:8px 0;border-bottom:1px solid var(--line)">
                <div style="font-size:13px">{{ r.description }}</div>
                <div style="font-size:11px;color:var(--mut);margin-top:2px">{{ r.user?.name || 'System' }} • {{ historyWhen(r.created_at) }}</div>
            </div>
            <button class="btn ghost" style="margin-top:14px" @click="historySheet = false">{{ t('common.close') }}</button>
        </Sheet>

        <!-- Phase 6B — the fill-in-Excel bulk sheet, dry-run first always -->
        <Sheet v-model="bulkOpen" :title="'📥 ' + t('os.bulkBtn')">
            <HowToHint :text="t('os.bulkHowTo')" />
            <div class="field">
                <label>{{ t('stock.category') }}</label>
                <select v-model="bulkCategory">
                    <option value="">{{ t('stock.allCategories') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.parent_id ? '— ' : '' }}{{ c.name }}</option>
                </select>
            </div>
            <a :href="bulkSheetUrl" class="btn ghost sm" style="margin-bottom:14px;display:inline-block;text-decoration:none">⬇ {{ t('os.downloadSheet') }}</a>
            <div v-if="suppliers" class="field">
                <label>{{ t('os.supplier') }}</label>
                <select v-model="bulkSupplier">
                    <option value="">Nil — {{ t('os.noSupplier') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('stock.chooseFile') }}</label>
                <input type="file" accept=".csv,text/csv" @change="bulkFile = $event.target.files[0]; bulkPreview = null">
            </div>
            <template v-if="bulkPreview">
                <div class="card" style="margin-bottom:10px;max-height:40vh;overflow:auto">
                    <b style="font-size:13px">{{ t('os.previewTitle', { n: bulkPreview.changes.length }) }}</b>
                    <div v-for="(ch, i) in bulkPreview.changes" :key="i" style="font-size:12px;padding:4px 0;border-top:1px solid var(--line)">
                        {{ ch.label }} — {{ ch.current }} <b>+{{ ch.add }}</b><template v-if="ch.cost !== null"> @ {{ ch.cost }}</template>
                    </div>
                    <template v-if="bulkPreview.unmatched.length">
                        <b style="font-size:12.5px;color:var(--rose);display:block;margin-top:8px">{{ t('os.unmatchedTitle', { n: bulkPreview.unmatched.length }) }}</b>
                        <div v-for="(u, i) in bulkPreview.unmatched" :key="'u' + i" style="font-size:12px;color:var(--rose);padding:2px 0">{{ u }}</div>
                    </template>
                </div>
                <button class="btn" :disabled="bulkBusy || !bulkPreview.changes.length" @click="bulkUpload(false)">{{ bulkBusy ? '...' : t('os.applyNow', { n: bulkPreview.changes.length }) }}</button>
            </template>
            <button v-else class="btn" :disabled="bulkBusy || !bulkFile" @click="bulkUpload(true)">{{ bulkBusy ? '...' : t('os.previewBtn') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="bulkOpen = false">{{ t('common.cancel') }}</button>
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

        <SmartScanSheet v-model="smartScanOpen" @detected="onSmartScanDetected" @barcode-found="onSmartScanBarcodeFound" />

        <Teleport to="body">
            <div v-if="scannerOpen" id="scanwrap">
                <div class="scan-top">
                    <button class="scan-x" @click="closeScanner">✕</button>
                    <div style="color:#fff;font-weight:700">{{ t('pos.scanProductTitle') }}</div>
                </div>
                <div id="stock-reader"></div>
                <div class="scan-frame"></div>
                <div class="scan-hint">{{ scanHint }}</div>
                <div class="scan-manual">
                    <button class="btn ghost" style="background:rgba(0,0,0,.55);color:#fff;border-color:rgba(255,255,255,.25)" @click="closeScanner">
                        {{ t('common.cancel') }}
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
