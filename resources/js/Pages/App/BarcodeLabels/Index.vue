<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarcodeLabelCard from '@/Components/BarcodeLabelCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { labelSizeMm } from '@/support/labelSizes';
import { thermalModuleWidth, THERMAL_BAR_HEIGHT_MM, detailedBarcodeDots } from '@/support/barcodeFit';
import { searchProducts } from '@/support/clientProductSearch';
import { buildShareMetadata, canShareLabelFiles } from '@/support/bluetoothPrintShare';
import { printedLabelKey } from '@/support/labelPrintKey';
import { printableRowsOf } from '@/support/labelSelection';
import { drawThermalLabel, DRAW_BUILD } from '@/support/thermalLabelCanvas';

const props = defineProps({
    products: Array,
    shop: Object,
    // barcode -> { last, copies }: which stickers have already been
    // printed, so a delivery can be finished rather than re-done
    printed: { type: Object, default: () => ({}) },
    // arriving from Stock's 🏷️ button: open on this article alone,
    // already ticked
    openFor: { type: Number, default: null },
});
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const settings = computed(() => props.shop?.label_settings || {});
const sizeMm = computed(() => labelSizeMm(settings.value));
// Phase 43 (docs/BT_PRINT_PLAN.md বিকল্প ক) — only shown in thermal mode,
// and only when this browser can actually share files (the printer-helper
// companion app registers as a share target for exactly this)
const showBluetoothPrint = computed(() => settings.value.print_mode === 'thermal' && canShareLabelFiles());

const q = ref(new URLSearchParams(window.location.search).get('q') || '');

/*
 * Opened from Stock's 🏷️ button: show that article alone and tick
 * everything it prints, so the owner lands one press from paper.
 *
 * Deliberately not auto-printing — paper gets used, and the copies are
 * still his to adjust.
 */
onMounted(() => {
    if (!props.openFor) return;

    const product = props.products.find((p) => p.id === props.openFor);
    if (!product) return;

    q.value = product.name;
    printableRowsOf(product).forEach((r) => { selected.value[r.key] = r.copies; });
});
const selected = ref({}); // { key: copies }

function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}

/**
 * Selected = the key is PRESENT, not that its value is truthy.
 *
 * The copies box was rendered with v-if="selected[key]", so clearing it to
 * type a new number made the value falsy and unmounted the input
 * mid-keystroke — the row appeared to deselect itself and the field
 * vanished ("1 thake eta remove korle screen cole jacche"). An empty box
 * is a row still selected, with its count not yet typed.
 */
function isSelected(key) {
    return key in selected.value;
}

/** An empty or nonsense count becomes 1 when the box loses focus, so nothing silently drops out of the batch. */
function normalizeCopies(key) {
    const n = Math.floor(Number(selected.value[key]));
    selected.value[key] = Number.isFinite(n) && n > 0 ? n : 1;
}

function bumpCopies(key, by) {
    const n = Math.floor(Number(selected.value[key]));
    selected.value[key] = Math.max(1, (Number.isFinite(n) ? n : 0) + by);
}

function toggleProduct(p) {
    if (!p.barcode) return;
    const key = 'p:' + p.id;
    if (isSelected(key)) delete selected.value[key];
    else selected.value[key] = 1;
}
function toggleVariant(v) {
    if (!v.barcode) return;
    const key = 'v:' + v.id;
    if (isSelected(key)) delete selected.value[key];
    else selected.value[key] = 1;
}
/** docs/ARTICLE_SERIES_PLAN.md §9 — one label per color (every size shares it) instead of one per size — copies default to that color's TOTAL stock across sizes, editable same as any other row. */
function toggleColorBarcode(cb) {
    const key = 'c:' + cb.id;
    if (isSelected(key)) delete selected.value[key];
    else selected.value[key] = Math.max(1, cb.stock);
}
/** Part 1 — a supplier/brand LOT's own sticker; defaults the copy count to its own stock, same convention as every other row here. */
function toggleSourceLine(line) {
    if (!line.barcode) return;
    const key = 'l:' + line.id;
    if (isSelected(key)) delete selected.value[key];
    else selected.value[key] = Math.max(1, line.stock);
}
function selectAllVariants(p) {
    const rows = printableRowsOf(p);
    if (!rows.length) return;

    const allSelected = rows.every((r) => isSelected(r.key));
    rows.forEach((r) => {
        if (allSelected) delete selected.value[r.key];
        else selected.value[r.key] = r.copies;
    });
}

/**
 * Every product on screen at once.
 *
 * Khaled, after a stock intake: "ekta ekta print dite gia onk somoy
 * nosto hocche". Search the article numbers that just came in, press
 * this, press print — the whole delivery's stickers in one go instead of
 * one product at a time. Respects the search filter, so it only ever
 * selects what he can see.
 */
function selectEverythingShown() {
    const rows = filtered.value.flatMap((p) => printableRowsOf(p));
    if (!rows.length) return;

    const allSelected = rows.every((r) => isSelected(r.key));
    rows.forEach((r) => {
        if (allSelected) delete selected.value[r.key];
        else selected.value[r.key] = r.copies;
    });
}

/**
 * The rows whose stickers have never been printed.
 *
 * Khaled, after a delivery: "kongular barcode print korchi kongular
 * koreni". This is the answer to that in one press — finish what is
 * left instead of re-printing whole articles to be sure.
 */
const neverPrintedRows = computed(() => filtered.value.flatMap((p) => printableRowsOf(p).map((r) => {
    const v = (p.variants || []).find((x) => 'v:' + x.id === r.key);
    const cb = (p.color_barcodes || []).find((x) => 'c:' + x.id === r.key);
    const barcode = v?.barcode ?? cb?.barcode ?? (r.key.startsWith('p:') ? p.barcode : null);

    return { ...r, barcode };
}).filter((r) => r.barcode && !printedInfo(r.barcode))));

function selectNeverPrinted() {
    const rows = neverPrintedRows.value;
    if (!rows.length) return;

    const allSelected = rows.every((r) => isSelected(r.key));
    rows.forEach((r) => {
        if (allSelected) delete selected.value[r.key];
        else selected.value[r.key] = r.copies;
    });
}

/** Stock across every size of a product, for the header row of a variant product. */
function totalStock(p) {
    return (p.variants || []).reduce((sum, v) => sum + Number(v.stock || 0), 0);
}

function generateBarcode(v) {
    router.patch(route('app.productVariants.generateBarcode', v.id), {}, { preserveScroll: true, preserveState: true });
}
function generateProductBarcode(p) {
    router.patch(route('app.products.generateBarcode', p.id), {}, { preserveScroll: true, preserveState: true });
}

// one shared matcher (support/clientProductSearch.js): multi-word any
// order, variant size/colour, category, brand, supplier, ZV codes,
// best matches first — the old bare includes() found none of those
const filtered = computed(() => searchProducts(props.products, q.value));

// ↑/↓ walks the results, Enter focuses the highlighted product's first
// qty box — works for the keyboard and for a hardware scanner's Enter
const activeIdx = ref(-1);
watch(q, () => { activeIdx.value = filtered.value.length ? 0 : -1; });
function moveActive(step) {
    if (!filtered.value.length) return;
    activeIdx.value = Math.min(filtered.value.length - 1, Math.max(0, activeIdx.value + step));
    document.getElementById('bc-prod-' + filtered.value[activeIdx.value]?.id)?.scrollIntoView({ block: 'nearest' });
}
function focusActive() {
    const p = filtered.value[activeIdx.value];
    if (!p) return;
    const el = document.getElementById('bc-prod-' + p.id);
    el?.scrollIntoView({ block: 'nearest' });
    // a plain product (no variants) with its own barcode: Enter ticks it
    // the same as a tap, so the qty box exists to focus into — a
    // hardware scanner's Enter can go straight from typing to a count
    if (!p.variants?.length && p.barcode && !isSelected('p:' + p.id)) {
        toggleProduct(p);
    }
    nextTick(() => el?.querySelector('input[type="number"]')?.focus());
}

const totalCopies = computed(() => Object.values(selected.value).reduce((a, b) => a + b, 0));

/**
 * Selected rows the current search has hidden.
 *
 * A tick survives a search change on purpose — an intake is filed one
 * article number at a time and printed in one run at the end. But nothing
 * said so, and a forgotten "select everything shown" from before the
 * search stayed in the total: one product on screen, stock 16, and the
 * button offering to print 1347 stickers. The number was right; what was
 * missing was any way to see where it came from.
 */
const visibleKeys = computed(() => new Set(filtered.value.flatMap((p) => [
    'p:' + p.id,
    ...(p.variants || []).map((v) => 'v:' + v.id),
    ...(p.color_barcodes || []).map((cb) => 'c:' + cb.id),
])));
const hiddenCopies = computed(() => Object.entries(selected.value)
    .filter(([key]) => !visibleKeys.value.has(key))
    .reduce((sum, [, copies]) => sum + copies, 0));

/** Drop every tick the search is hiding, keeping what is on screen — the one-tap way out of a total the owner cannot account for. */
function keepOnlyShown() {
    for (const key of Object.keys(selected.value)) {
        if (!visibleKeys.value.has(key)) delete selected.value[key];
    }
}

const labels = ref([]); // flattened list of {name, variantLabel, barcode, price, discount_price}
const printing = ref(false);

/*
 * How many cards the preview puts on screen before the owner asks for
 * more.
 *
 * Every card draws its own barcode, so the grid's cost is linear in the
 * number of COPIES, not in the number of distinct stickers — 1347 copies
 * of eight stickers still mounted 1347 components. The client's tablet
 * ran out of memory and Android restarted the app at the splash screen.
 *
 * `labels` itself is never capped: both print paths read it in full, so
 * what gets printed is unchanged. Only what is rendered for looking at is
 * limited, and expandAll() puts every card in the DOM before any path
 * that needs them (browser print, and the html2canvas capture fallback).
 */
const PREVIEW_CAP = 60;
const CONFIRM_ABOVE = 300;
const previewLimit = ref(PREVIEW_CAP);
const shownLabels = computed(() => labels.value.slice(0, previewLimit.value));

/** Mount every card and wait for the DOM to catch up — for the paths that print what is actually on the page. */
async function expandAll() {
    if (previewLimit.value >= labels.value.length) return;
    previewLimit.value = labels.value.length;
    await nextTick();
}

// Phase 43 — each BarcodeLabelCard emits 'rendered' once its barcode/header
// have actually finished drawing (see the component); the Bluetooth-print
// capture waits on this count instead of guessing with a fixed delay.
const renderedCount = ref(0);
function onLabelRendered() { renderedCount.value++; }
/**
 * Waits for every label card to report it has drawn — then gives up.
 *
 * This used to spin on requestAnimationFrame with no way out, so one card
 * that never reported (a throw while drawing, or a component Vue patched
 * in place instead of remounting, which fires no onMounted) hung the
 * print for good: no share sheet, no error, nothing. Both of those causes
 * are fixed at the source, but a wait with no exit is the thing that
 * turned either of them into a silent dead end, so it gets a deadline.
 *
 * Timing out is not failure: the cards are already in the DOM, and the
 * caller rasterizes whatever is there. Resolves false so it can say so.
 */
function waitForAllRendered(timeoutMs = 10000) {
    if (renderedCount.value >= labels.value.length) return Promise.resolve(true);
    return new Promise((resolve) => {
        const deadline = Date.now() + timeoutMs;
        const check = () => {
            if (renderedCount.value >= labels.value.length) return resolve(true);
            if (Date.now() > deadline) return resolve(false);
            requestAnimationFrame(check);
        };
        check();
    });
}

const bluetoothPrinting = ref(false);
const bluetoothProgress = ref('');

/**
 * Phase 43 (docs/BT_PRINT_PLAN.md বিকল্প ক) — rasterizes each already-
 * rendered `.label-card` (the exact same DOM the browser/PC print path
 * shows — never a second rendering path) into a PNG at EXACTLY
 * widthMm×8 × heightMm×8 pixels (203dpi = 8 dots/mm, matching the
 * printer's own physical dot density) and hands them to Android's share
 * sheet. html2canvas's own scale/devicePixelRatio handling can't be
 * trusted to land on this exact pixel count on every phone, so every
 * capture is redrawn onto a fixed-size canvas as a final step — the
 * printer-helper app prints raw dots 1:1 and never rescales, so a wrong
 * pixel size here would print at the wrong physical size on the label.
 *
 * Returns the finished file rather than sharing it, and is started as
 * soon as the preview is on screen. navigator.share() needs a live user
 * gesture and Chrome lets that lapse after a few seconds; rasterizing
 * three or more labels with html2canvas takes longer than that, so doing
 * this work after the tap got the share refused outright on any real
 * batch — two labels went through, three did nothing at all. By the time
 * the button is tapped this has already finished, so the tap shares
 * straight away.
 */
async function buildLabelSheet() {
    if (!labels.value.length) return null;
    try {
        const targetW = Math.round(sizeMm.value.width * 8);
        const targetH = Math.round(sizeMm.value.height * 8);
        const { default: JsBarcode } = await import('jsbarcode');

        // The fast path draws every sticker itself, so it needs neither
        // the cards on screen nor the wait for them to finish rendering
        // — which on a slow tablet was most of the delay before the tap
        // even became possible. It gives up as a whole, not label by
        // label, so the fallback below stays the code that has been
        // printing correctly all along.
        if (thermal.value) {
            const direct = await buildSheetDirectly(targetW, targetH, JsBarcode);
            if (direct) return direct;
        }

        // the capture fallback rasterizes the cards themselves, so it
        // needs the ones the preview cap is holding back
        await expandAll();
        if (!await waitForAllRendered()) {
            // say so rather than printing a half-drawn batch silently
            toast('⚠️ ' + t('bc.bluetoothSlowRender'));
        }
        await nextTick();
        const { default: html2canvas } = await import('html2canvas');
        const cards = document.querySelectorAll('#label-print-overlay .label-card');
        if (!cards.length) { toast('❌ ' + t('bc.bluetoothNoLabels')); return; }

        // Live-caught at the client: 22 separate PNGs never opened the
        // Android share sheet at all (one PNG per copy). Two changes make
        // the count irrelevant: identical labels collapse to ONE image
        // plus a copies count (the printer repeats it via TSPL's own
        // PRINT n), and the distinct images are stacked into a SINGLE
        // tall PNG the helper app slices back apart — so a 50-label run
        // always shares exactly one file.
        const distinct = []; // [{ key, canvas, copies }]
        const indexByKey = new Map();
        for (let i = 0; i < cards.length; i++) {
            const key = printedLabelKey(labels.value[i] ?? {}, settings.value, thermal.value);
            const seen = indexByKey.get(key);
            if (seen !== undefined) {
                distinct[seen].copies++;
                continue;
            }

            bluetoothProgress.value = t('bc.bluetoothRendering', { n: distinct.length + 1, total: cards.length });
            // scale: 3 — supersample well above the label's own ~8px/mm
            // print density so the final downscale (next step) anti-
            // aliases text/barcode edges cleanly instead of relying on
            // whatever devicePixelRatio this particular phone happens to
            // report.
            const raw = await html2canvas(cards[i], { backgroundColor: '#ffffff', scale: 3 });
            const fixed = document.createElement('canvas');
            fixed.width = targetW;
            fixed.height = targetH;
            const ctx = fixed.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, targetW, targetH);
            ctx.drawImage(raw, 0, 0, targetW, targetH);

            // CRITICAL for scannability (confirmed live — the captured
            // barcode would not scan): resizing a rasterized barcode makes
            // its modules land on fractional printer dots, so bar widths
            // come out uneven and CODE128 dies — the exact failure mode
            // Phase 41's whole-dot rule exists to prevent. The capture's
            // blurry bars are therefore whited out and replaced with a
            // JsBarcode render done DIRECTLY at this canvas's resolution,
            // where 1 canvas px == 1 printer dot, every module an exact
            // whole number of dots.
            const lbl = labels.value[i];
            const svgEl = cards[i].querySelector('svg');
            if (lbl?.barcode && svgEl && svgEl.childNodes.length) {
                const encoded = lbl.scanCode || lbl.barcode;
                const fit = thermalModuleWidth(encoded, sizeMm.value.width - 5);
                if (fit.fits) {
                    const bc = document.createElement('canvas');
                    try {
                        JsBarcode(bc, encoded, {
                            format: 'CODE128',
                            width: Math.max(1, Math.round(fit.moduleMm * 8)), // dots per module
                            height: THERMAL_BAR_HEIGHT_MM * 8, // bars at 8 dots/mm, same height the card itself draws
                            margin: 0,
                            displayValue: settings.value.show_barcode_number !== false,
                            text: lbl.barcode,
                            fontSize: 16,
                        });
                    } catch (e) { /* invalid CODE128 value — keep the captured fallback */ }
                    if (bc.width > 0 && bc.width <= targetW) {
                        const cardRect = cards[i].getBoundingClientRect();
                        const svgRect = svgEl.getBoundingClientRect();
                        const sy = targetH / cardRect.height;
                        let destY = Math.max(0, Math.round((svgRect.top - cardRect.top) * sy));

                        // The white-out below must never reach above what's
                        // printed directly over the bars. html2canvas lays
                        // text out a little differently from the browser, so
                        // a band anchored only on the svg's own measured top
                        // could sit a few dots high and wipe the lower half
                        // of the shop name — exactly what Khaled's printed
                        // label showed, with "Baby Park & Liberty" sliced
                        // through the middle. Clamping to the previous
                        // element's bottom makes that structurally
                        // impossible however the measurements drift.
                        // +4 dots (0.5mm), not flush: a glyph can still sit a
                        // hair below its own line box, and this gap is also
                        // what keeps the bars from crowding the name.
                        const prev = svgEl.previousElementSibling;
                        if (prev) {
                            const prevBottom = Math.round((prev.getBoundingClientRect().bottom - cardRect.top) * sy);
                            destY = Math.max(destY, prevBottom + 4);
                        }

                        // clear exactly the barcode's own slot, from that
                        // clamped top down to where the captured svg ended
                        const svgBottom = Math.round((svgRect.bottom - cardRect.top) * sy);
                        const clearH = Math.min(targetH - destY, Math.max(svgBottom - destY, bc.height));
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, destY, targetW, clearH);
                        ctx.imageSmoothingEnabled = false;
                        ctx.drawImage(bc, Math.round((targetW - bc.width) / 2), destY);
                    }
                }
            }

            indexByKey.set(key, distinct.length);
            distinct.push({ key, canvas: fixed, copies: 1 });
        }
        if (!distinct.length) { toast('❌ ' + t('bc.bluetoothRenderFailed')); return; }

        return await stackIntoSheet(distinct, targetW, targetH);
    } catch (e) {
        // this runs ahead of the tap, so a failure here has no share sheet
        // to report through — say it now rather than leaving the button to
        // look broken later
        toast('❌ ' + t('bc.bluetoothShareFailed', { msg: e?.message || String(e) }));

        return null;
    }
}

// The sheet for the labels currently on screen, rendered ahead of the tap
// (see buildLabelSheet). Thrown away whenever the preview closes or its
// contents change, so a stale sheet can never be the thing that prints.
/**
 * The distinct labels stacked into the one tall PNG the helper app
 * slices back apart, with the per-label copies count riding in the share
 * metadata. Shared by both build paths so they can never disagree about
 * the format the app is expecting.
 */
async function stackIntoSheet(distinct, targetW, targetH) {
    if (!distinct.length) { toast('❌ ' + t('bc.bluetoothRenderFailed')); return null; }

    // label k occupies rows k*targetH .. (k+1)*targetH-1
    const sheet = document.createElement('canvas');
    sheet.width = targetW;
    sheet.height = targetH * distinct.length;
    const sheetCtx = sheet.getContext('2d');
    sheetCtx.fillStyle = '#ffffff';
    sheetCtx.fillRect(0, 0, sheet.width, sheet.height);
    distinct.forEach((d, k) => sheetCtx.drawImage(d.canvas, 0, k * targetH));

    const blob = await new Promise((resolve) => sheet.toBlob(resolve, 'image/png'));
    if (!blob) { toast('❌ ' + t('bc.bluetoothRenderFailed')); return null; }

    return {
        files: [new File([blob], 'labels.png', { type: 'image/png' })],
        text: buildShareMetadata(settings.value, { labelHeightPx: targetH, copies: distinct.map((d) => d.copies) }),
    };
}

/**
 * The whole sheet drawn directly, without the page or html2canvas — or
 * null if any one sticker is unusual, in which case the caller falls
 * back to the capture path for the entire batch rather than mixing two
 * ways of drawing in one run.
 */
async function buildSheetDirectly(targetW, targetH, JsBarcode) {
    const distinct = [];
    const indexByKey = new Map();

    for (const label of labels.value) {
        const key = printedLabelKey(label ?? {}, settings.value, true);
        const seen = indexByKey.get(key);
        if (seen !== undefined) {
            distinct[seen].copies++;
            continue;
        }

        const drawn = drawnThermalCanvas(label, targetW, targetH, JsBarcode);
        if (!drawn.canvas) {
            // named, not silent: "it was slow again" could not tell us
            // whether this path ran at all
            say(t('bc.logDirectSkipped', { reason: drawn.reason }), true);

            return null;
        }

        indexByKey.set(key, distinct.length);
        distinct.push({ key, canvas: drawn.canvas, copies: 1 });
    }

    if (!distinct.length) return null;

    say(t('bc.logDirectDrew', { n: distinct.length, build: DRAW_BUILD }));
    // which template/size THIS device actually rendered with — a
    // screenshot of this line has settled template-vs-stale-bundle
    // questions faster than any guess (printer-helper discipline)
    say(`template: ${settings.value.template === 'detailed' ? 'detailed' : 'classic'} • label ${sizeMm.value.width}x${sizeMm.value.height}mm`);

    return stackIntoSheet(distinct, targetW, targetH);
}

/**
 * One thermal sticker, drawn directly at printer resolution — or null if
 * anything about it is unusual, which sends it down the old
 * html2canvas path untouched.
 *
 * The bars are JsBarcode at whole dots, exactly as the capture path
 * re-stamped them afterwards; here that IS the drawing, so the capture,
 * the downscale and the white-out all disappear.
 */
function drawnThermalCanvas(label, targetW, targetH, JsBarcode) {
    try {
        const encoded = label?.scanCode || label?.barcode;
        const isDetailed = settings.value.template === 'detailed';
        let barcodeCanvas = null;

        if (encoded) {
            // detailed template: bars scale with the label (~90% width,
            // ~38% height, biggest whole-dot module that fits) and the
            // code text is drawn by the layout itself, under the bars.
            // Classic keeps its proven 2-dot module + fixed 9mm exactly.
            const detail = isDetailed ? detailedBarcodeDots(encoded, targetW, targetH) : null;
            const fit = thermalModuleWidth(encoded, sizeMm.value.width - 5);
            if (detail ? !detail.fits : !fit.fits) return { reason: `বারকোড লেবেলে আঁটে না (${(detail || fit).modules} মডিউল)` };

            barcodeCanvas = document.createElement('canvas');
            // bars ONLY — the readable code is drawn by the layout itself
            // (both templates), fitted to the label. JsBarcode's own text
            // at a fixed 16px widened the canvas past a 38mm label for
            // long article codes and forced the whole batch onto the slow
            // capture path ("327 > 304", Baby Park live).
            JsBarcode(barcodeCanvas, encoded, {
                format: 'CODE128',
                width: detail ? detail.moduleDots : Math.max(1, Math.round(fit.moduleMm * 8)), // dots per module
                height: detail ? detail.heightDots : THERMAL_BAR_HEIGHT_MM * 8,
                margin: 0,
                displayValue: false,
            });
            if (!barcodeCanvas.width) return { reason: 'বারকোড আঁকা যায়নি' };
            if (barcodeCanvas.width > targetW) {
                return { reason: `বারকোড লেবেলের চেয়ে চওড়া (${barcodeCanvas.width} > ${targetW})` };
            }
        }

        const canvas = document.createElement('canvas');
        canvas.width = targetW;
        canvas.height = targetH;

        return {
            canvas: drawThermalLabel(canvas, {
                label: label ?? {},
                settings: settings.value,
                // the detailed template's top line is this same EDITABLE
                // label-header setting (owner sets it to seamanstore.com)
                headerText: props.shop?.resolved_label_header || props.shop?.name || '',
                barcodeCanvas,
            }),
        };
    } catch (e) {
        // fall through to html2canvas rather than print nothing
        return { reason: e?.message || String(e) };
    }
}

/** How many of the labels about to print carry no real price (0/empty, no discount either) — surfaced before paper is used. */
const noPriceCount = computed(() => labels.value.filter((l) => !(Number(l.price) > 0) && !(Number(l.discount_price) > 0)).length);

let preparedSheet = null;

/*
 * Whether that sheet actually EXISTS yet.
 *
 * The print button used to be tappable the moment the preview opened,
 * while the images were still being drawn. Tapping then made share()
 * wait — and Android expires the "the user just tapped" permission after
 * a few seconds, so the share sheet simply never opened and nothing said
 * why. Khaled, on a slow tablet with a size-by-size batch: "via bluth
 * dile render hoye print option ase na r".
 *
 * So the button stays disabled, showing its progress, until the file is
 * in hand. Then the tap is instant and the gesture is always still live.
 */
const sheetReady = ref(false);

/** Step-by-step record of the last Bluetooth attempt, shown in the overlay. */
const bluetoothLog = ref([]);
function say(text, bad = false) {
    bluetoothLog.value.push({ text: (bad ? '❌ ' : '• ') + text, bad });
}

function prepareSheetAhead() {
    sheetReady.value = false;
    preparedSheet = buildLabelSheet().catch(() => null);
    preparedSheet.finally(() => { sheetReady.value = true; });
}

watch([printing, labels], () => {
    preparedSheet = null;
    sheetReady.value = false;
    bluetoothLog.value = [];
    if (printing.value && labels.value.length) prepareSheetAhead();
});

async function printViaBluetooth() {
    if (bluetoothPrinting.value || !labels.value.length) return;
    bluetoothPrinting.value = true;
    bluetoothProgress.value = t('bc.bluetoothPreparing');
    // deliberately NOT cleared here: the sheet is built before the tap,
    // so wiping the log on tap would throw away the half that explains
    // how the drawing went. It is cleared when the preview's contents
    // change instead.
    let handedOver = false;
    try {
        // already resolved in the normal case, so the user gesture that
        // opened this is still live when share() is called below
        const sheet = await (preparedSheet ?? buildLabelSheet());
        if (!sheet) {
            // buildLabelSheet already toasted, but a toast is exactly what
            // keeps being missed — say it here too, where it stays
            say(t('bc.logNoSheet'), true);

            return;
        }

        const kb = Math.round((sheet.files[0]?.size ?? 0) / 1024);
        say(t('bc.logSheetReady', { n: labels.value.length, kb }));

        if (!navigator.canShare({ files: sheet.files })) {
            say(t('bc.logCannotShare'), true);
            toast('❌ ' + t('bc.bluetoothNotSupported'));

            return;
        }

        say(t('bc.logSharing'));
        await navigator.share({ ...sheet, title: 'Zaylotix লেবেল প্রিন্ট' });
        say(t('bc.logShared'));
        handedOver = true;
        recordPrinted();
    } catch (e) {
        // AbortError is the native sheet being dismissed — but it is ALSO
        // what Chrome throws when the tap's permission has expired, which
        // is the failure that has been chased all along. Both are named,
        // so the next report can tell them apart instead of guessing.
        say(t('bc.logShareFailed', { name: e?.name || 'Error', msg: e?.message || String(e) }), true);
        if (e?.name !== 'AbortError') {
            toast('❌ ' + t('bc.bluetoothShareFailed', { msg: e?.message || String(e) }));
        }
    } finally {
        bluetoothPrinting.value = false;
        bluetoothProgress.value = '';

        // A sheet that was actually handed over is spent: re-render, so a
        // second print can never send a file built from labels since
        // replaced. One that FAILED was never consumed — keeping it means
        // the retry is a single instant tap, which is the whole point when
        // the first tap failed because the drawing took too long.
        if (handedOver) {
            preparedSheet = null;
            if (printing.value && labels.value.length) prepareSheetAhead();
        }
    }
}

/** Builds the selected list and opens the preview overlay — print itself is now a deliberate second tap (doPrint), not automatic, so there's always a real chance to review before paper gets used. */
function buildAndPreview() {
    const list = [];
    for (const p of props.products) {
        const productCopies = selected.value['p:' + p.id];
        if (productCopies) {
            for (let i = 0; i < productCopies; i++) {
                list.push({ name: p.name, variantLabel: '', barcode: p.barcode, price: p.price, discount_price: p.discount_price, brandName: p.brand_name || null });
            }
        }
        for (const v of p.variants || []) {
            const copies = selected.value['v:' + v.id];
            if (copies) {
                for (let i = 0; i < copies; i++) {
                    list.push({ name: p.name, variantLabel: variantLabel(v), barcode: v.barcode, scanCode: v.scan_code, price: v.price ?? p.price, discount_price: null, brandName: v.brand_name || null });
                }
            }
            // Part 1 — a sticker per SUPPLIER/BRAND LOT, at that lot's own
            // sell price, scanning straight to that exact lot (its ZL barcode)
            for (const line of v.sources || []) {
                const lineCopies = selected.value['l:' + line.id];
                if (!lineCopies) continue;
                for (let i = 0; i < lineCopies; i++) {
                    list.push({ name: p.name, variantLabel: variantLabel(v), barcode: line.barcode, scanCode: line.scan_code, price: line.price, discount_price: null, brandName: line.supplier_name ? (t('os.supplier') + ': ' + line.supplier_name) : (v.brand_name || null) });
                }
            }
        }
        for (const cb of p.color_barcodes || []) {
            const copies = selected.value['c:' + cb.id];
            if (!copies) continue;
            for (let i = 0; i < copies; i++) {
                list.push({ name: p.name, variantLabel: cb.color, barcode: cb.barcode, scanCode: cb.scan_code, price: p.price, discount_price: p.discount_price, brandName: p.brand_name || null });
            }
        }
    }
    if (!list.length) return;

    /*
     * A batch this size is nearly always a mistake, and on the client's
     * tablet it was a fatal one — 1347 cards mounted at once took the
     * WebView's memory and Android killed the whole app, which from the
     * shop floor looked like "Preview চাপলে অ্যাপ বন্ধ হয়ে যায়". The cap
     * below keeps that from being fatal; this keeps it from being a
     * surprise.
     */
    if (list.length > CONFIRM_ABOVE && !window.confirm(t('bc.hugeBatchConfirm', { n: list.length }))) return;

    renderedCount.value = 0;
    previewLimit.value = PREVIEW_CAP;
    labels.value = list;
    printing.value = true;
}

/**
 * Tells the server what just went to a printer.
 *
 * "Sent" is the honest claim — paper can jam — so this fires when the
 * browser's print dialog opens or the sheet is handed to the helper
 * app, not on a confirmation that never comes back. Keyed on the
 * barcode, which is identical across the shops of one business, so a
 * sticker printed at Baby Park counts at Liberty too.
 */
function recordPrinted() {
    const rows = [];
    for (const p of props.products) {
        const productCopies = selected.value['p:' + p.id];
        if (productCopies && p.barcode) rows.push({ barcode: p.barcode, copies: productCopies, product_id: p.id });

        for (const v of p.variants || []) {
            const copies = selected.value['v:' + v.id];
            if (copies && v.barcode) rows.push({ barcode: v.barcode, copies, product_id: p.id, product_variant_id: v.id });
            for (const line of v.sources || []) {
                const lineCopies = selected.value['l:' + line.id];
                if (lineCopies && line.barcode) rows.push({ barcode: line.barcode, copies: lineCopies, product_id: p.id, product_variant_id: v.id });
            }
        }
        for (const cb of p.color_barcodes || []) {
            const copies = selected.value['c:' + cb.id];
            if (copies && cb.barcode) rows.push({ barcode: cb.barcode, copies, product_id: p.id, product_color_barcode_id: cb.id });
        }
    }
    if (!rows.length) return;

    // preserveState: the picker must not reset under the owner's hands
    // mid-print; the marks refresh on the next visit
    router.post(route('app.barcodeLabels.printed'), { rows }, {
        preserveState: true, preserveScroll: true, only: ['printed'],
    });
}

/** Has this row's sticker been printed before, and when last? */
function printedInfo(barcode) {
    return barcode ? (props.printed?.[barcode] ?? null) : null;
}

function printedLabel(barcode) {
    const info = printedInfo(barcode);
    if (!info) return '';
    const when = new Date(info.last.replace(' ', 'T'));
    const day = Number.isNaN(when.getTime()) ? '' : when.toLocaleDateString('bn-BD', { day: 'numeric', month: 'short' });

    return t('bc.printedOn', { n: info.copies, date: day });
}

async function doPrint() {
    // window.print() prints the DOM, so every copy has to be mounted
    // first — printing the capped view would silently produce 60 stickers
    // where the owner asked for all of them.
    await expandAll();
    recordPrinted();
    window.print();
}

function closePrint() {
    printing.value = false;
    labels.value = [];
}

const thermal = computed(() => settings.value.print_mode === 'thermal');

const printCss = computed(() => {
    const base = `
@page { size: ${sizeMm.value.width}mm ${sizeMm.value.height}mm; margin: 0; }
.label-grid { grid-template-columns: repeat(${settings.value.per_row || 2}, 1fr); }
@media print {
    .label-card { width: ${sizeMm.value.width}mm; height: ${sizeMm.value.height}mm; }
}
`;
    if (!thermal.value) return base; // classic: byte-identical to pre-Phase-41
    // Phase 41 — offset_x/y shift the whole label's content to correct a
    // roll narrower than the print head sitting off-center (the label
    // settings page's calibration test print is how the right values get
    // found). The page box itself stays exactly the label size.
    const ox = Number(settings.value.offset_x_mm) || 0;
    const oy = Number(settings.value.offset_y_mm) || 0;
    return base + `
@media print {
    .label-card.thermal { transform: translate(${ox}mm, ${oy}mm); }
}
`;
});
</script>

<template>
    <Head :title="t('nav.barcode')" />
    <AppLayout active="more">
        <div class="pgttl">🏷️ {{ t('nav.barcode') }}</div>
        <div class="pgsub">{{ t('bc.subtitle') }}</div>

        <Link :href="route('app.settings.receiptLabel')" class="no-print" style="display:inline-flex;align-items:center;gap:4px;font-size:13px;color:var(--gold2);margin-bottom:10px">
            ⚙️ {{ t('bc.labelSettingsLink') }} ({{ sizeMm.width }}×{{ sizeMm.height }}mm, {{ settings.per_row || 2 }}/{{ t('bc.perRow') }})
        </Link>

        <input v-model="q" :placeholder="t('bc.searchPlaceholder')" style="margin-bottom:8px"
            @keydown.down.prevent="moveActive(1)" @keydown.up.prevent="moveActive(-1)" @keydown.enter.prevent="focusActive()">
        <div v-if="q && !filtered.length" class="card" style="margin-bottom:12px;text-align:center;color:var(--mut);font-size:13px">
            🔍 {{ t('bc.searchEmpty') }}
        </div>
        <div v-if="filtered.length" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
            <button class="btn sm ghost" style="width:auto;padding:6px 10px" @click="selectEverythingShown">
                {{ t('bc.selectEverythingShown', { n: filtered.length }) }}
            </button>
            <button
                v-if="neverPrintedRows.length" class="btn sm ghost" style="width:auto;padding:6px 10px"
                @click="selectNeverPrinted"
            >
                {{ t('bc.selectNeverPrinted', { n: neverPrintedRows.length }) }}
            </button>
            <button v-if="totalCopies" class="btn sm ghost" style="width:auto;padding:6px 10px" @click="selected = {}">
                {{ t('bc.clearSelection') }}
            </button>
        </div>

        <div v-for="p in filtered" :key="p.id" :id="'bc-prod-' + p.id" class="card" style="padding:0;margin-bottom:8px;overflow:hidden">
            <div v-if="!p.variants?.length" class="row" @click="toggleProduct(p)" :class="{ incart: isSelected('p:' + p.id) }" :style="isSelected('p:' + p.id) ? 'border:none;background:var(--goldSoft)' : 'border:none'">
                <div class="ava">{{ p.emoji }}</div>
                <div class="mid">
                    <b>{{ p.name }}</b>
                    <span v-if="p.barcode">{{ p.barcode }} • {{ money(p.price) }}<span v-if="p.discount_price"> → {{ money(p.discount_price) }}</span></span>
                    <span v-else style="color:var(--rose)">{{ t('bc.noBarcode') }}</span>
                    <span :style="'color:' + (Number(p.stock) > 0 ? 'var(--mut)' : 'var(--rose)')">{{ t('bc.inStock', { n: p.stock ?? 0 }) }}</span>
                </div>
                <div class="end" @click.stop>
                    <button v-if="!p.barcode" class="btn sm ghost" style="width:auto;padding:6px 10px" @click="generateProductBarcode(p)">{{ t('bc.generateBarcode') }}</button>
                    <div v-else-if="isSelected('p:' + p.id)" class="copies">
                        <button type="button" class="copies-step" @click="bumpCopies('p:' + p.id, -1)">−</button>
                        <input v-model.number="selected['p:' + p.id]" type="number" min="1" inputmode="numeric" @blur="normalizeCopies('p:' + p.id)">
                        <button type="button" class="copies-step" @click="bumpCopies('p:' + p.id, 1)">+</button>
                    </div>
                </div>
            </div>

            <template v-else>
                <div class="row" style="border:none;background:var(--surface2, var(--card))">
                    <div class="ava">{{ p.emoji }}</div>
                    <div class="mid">
                        <b>{{ p.name }}</b>
                        <span>{{ t('bc.variantCount', { n: p.variants.length }) }} • {{ t('bc.totalStock', { n: totalStock(p) }) }}</span>
                    </div>
                    <div class="end" @click.stop>
                        <button class="btn sm ghost" style="width:auto;padding:6px 10px" @click="selectAllVariants(p)">{{ t('bc.selectAllVariants') }}</button>
                    </div>
                </div>
                <!-- docs/ARTICLE_SERIES_PLAN.md §9 — one label per color (every size shares it), an alternative to picking each size below when the shop hasn't turned "include size in barcode" on -->
                <div v-if="p.color_barcodes?.length" style="padding:8px 12px 2px 20px;font-size:11px;color:var(--mut)">{{ t('bc.colorLabelsHint') }}</div>
                <div v-for="cb in p.color_barcodes" :key="'c' + cb.id" class="row" style="border-top:1px solid var(--line);padding-left:20px" :class="{ incart: isSelected('c:' + cb.id) }" :style="isSelected('c:' + cb.id) ? 'background:var(--goldSoft)' : ''" @click="toggleColorBarcode(cb)">
                    <div class="ava" style="font-size:16px">🎨</div>
                    <div class="mid">
                        <b>{{ cb.color }}</b>
                        <span class="sku">{{ cb.barcode }} • {{ money(p.price) }}</span>
                        <!-- on the stock line, not a line of its own: a
                             fourth line overflowed the row and printed
                             over the one above it -->
                        <span>
                            <span :style="'color:' + (Number(cb.stock) > 0 ? 'var(--mut)' : 'var(--rose)')">{{ t('bc.inStock', { n: cb.stock ?? 0 }) }}</span>
                            <span :style="'color:' + (printedInfo(cb.barcode) ? 'var(--green)' : 'var(--gold2)')"> • {{ printedInfo(cb.barcode) ? printedLabel(cb.barcode) : t('bc.neverPrinted') }}</span>
                        </span>
                    </div>
                    <div class="end" @click.stop>
                        <div v-if="isSelected('c:' + cb.id)" class="copies">
                            <button type="button" class="copies-step" @click="bumpCopies('c:' + cb.id, -1)">−</button>
                            <input v-model.number="selected['c:' + cb.id]" type="number" min="1" inputmode="numeric" @blur="normalizeCopies('c:' + cb.id)">
                            <button type="button" class="copies-step" @click="bumpCopies('c:' + cb.id, 1)">+</button>
                        </div>
                    </div>
                </div>
                <div v-for="v in p.variants" :key="v.id" class="row" style="border-top:1px solid var(--line);padding-left:20px" :class="{ incart: isSelected('v:' + v.id) }" :style="isSelected('v:' + v.id) ? 'background:var(--goldSoft)' : ''" @click="toggleVariant(v)">
                    <div class="ava" style="font-size:16px">🏷️</div>
                    <div class="mid">
                        <b>{{ variantLabel(v) }}</b>
                        <span v-if="v.barcode">{{ v.barcode }} • {{ money(v.price ?? p.price) }}</span>
                        <span v-else style="color:var(--rose)">{{ t('bc.noBarcode') }}</span>
                        <!-- printing a sticker per pair means the count he
                             wants is almost always this number, so it goes
                             on every row rather than only in Stock -->
                        <span>
                            <span :style="'color:' + (Number(v.stock) > 0 ? 'var(--mut)' : 'var(--rose)')">{{ t('bc.inStock', { n: v.stock ?? 0 }) }}</span>
                            <span v-if="v.barcode" :style="'color:' + (printedInfo(v.barcode) ? 'var(--green)' : 'var(--gold2)')"> • {{ printedInfo(v.barcode) ? printedLabel(v.barcode) : t('bc.neverPrinted') }}</span>
                        </span>
                    </div>
                    <div class="end" @click.stop>
                        <button v-if="!v.barcode" class="btn sm ghost" style="width:auto;padding:6px 10px" @click="generateBarcode(v)">{{ t('bc.generateBarcode') }}</button>
                        <div v-else-if="isSelected('v:' + v.id)" class="copies">
                            <button type="button" class="copies-step" @click="bumpCopies('v:' + v.id, -1)">−</button>
                            <input v-model.number="selected['v:' + v.id]" type="number" min="1" inputmode="numeric" @blur="normalizeCopies('v:' + v.id)">
                            <button type="button" class="copies-step" @click="bumpCopies('v:' + v.id, 1)">+</button>
                        </div>
                    </div>
                </div>
                <!-- Part 1 — print a sticker PER SUPPLIER/BRAND LOT, at that
                     lot's own sell price; scanning it resolves to that
                     exact lot on the sell screen, no guessing -->
                <div v-for="line in v.sources || []" :key="'l' + line.id" class="row" style="border-top:1px solid var(--line);padding-left:36px" :class="{ incart: isSelected('l:' + line.id) }" :style="isSelected('l:' + line.id) ? 'background:var(--goldSoft)' : ''" @click="toggleSourceLine(line)">
                    <div class="ava" style="font-size:14px">🚚</div>
                    <div class="mid">
                        <b style="font-size:12.5px">{{ line.supplier_name || 'Nil' }}</b>
                        <span>{{ money(line.price) }} • {{ t('bc.inStock', { n: line.stock ?? 0 }) }}</span>
                    </div>
                    <div class="end" @click.stop>
                        <div v-if="isSelected('l:' + line.id)" class="copies">
                            <button type="button" class="copies-step" @click="bumpCopies('l:' + line.id, -1)">−</button>
                            <input v-model.number="selected['l:' + line.id]" type="number" min="1" inputmode="numeric" @blur="normalizeCopies('l:' + line.id)">
                            <button type="button" class="copies-step" @click="bumpCopies('l:' + line.id, 1)">+</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">🏷️</div>{{ t('bc.noProducts') }}</div>

        <div style="height:78px"></div>
        <div class="posbar">
            <!--
                Where a total the owner cannot account for comes from. One
                product on screen and "Preview (1347)" on the button was
                correct and completely unexplained: the ticks were from an
                earlier search. Naming them, with one tap to drop them, is
                the whole fix — the ticks themselves still survive a search
                on purpose (an intake is filed article by article and
                printed in one run).
            -->
            <div v-if="hiddenCopies" style="font-size:12px;color:var(--mut);margin-bottom:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span>{{ t('bc.hiddenSelected', { n: hiddenCopies }) }}</span>
                <button class="btn sm ghost" style="width:auto;padding:4px 10px" @click="keepOnlyShown">{{ t('bc.keepOnlyShown') }}</button>
            </div>
            <button class="btn" :disabled="!totalCopies" @click="buildAndPreview">
                {{ t('bc.preview') }} ({{ totalCopies }})
            </button>
        </div>

        <!-- print preview — doPrint is now a deliberate second tap, not automatic -->
        <Teleport to="body">
            <div v-if="printing" id="label-print-overlay">
                <component :is="'style'" v-html="printCss"></component>
                <div class="no-print" style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;background:var(--panel);border-bottom:1px solid var(--line)">
                    <button class="btn sm" @click="doPrint">🖨️ {{ t('bc.print') }}</button>
                    <!-- Phase 43 (docs/BT_PRINT_PLAN.md বিকল্প ক) — Zaylotix
                         Printer Helper (ছোট আলাদা অ্যাপ) ইনস্টল থাকলে এই
                         বাটনে সরাসরি Bluetooth-এ পাঠানো যাবে, কোনো PC লাগবে না -->
                    <button v-if="showBluetoothPrint" class="btn sm ghost" :disabled="bluetoothPrinting || !sheetReady" @click="printViaBluetooth">
                        {{ bluetoothPrinting || !sheetReady ? (bluetoothProgress || t('bc.bluetoothPreparing')) : '📱 ' + t('bc.bluetoothPrint') }}
                    </button>
                    <button class="btn sm ghost" @click="closePrint">{{ t('bc.close') }}</button>
                </div>
                <!-- "do not print ৳0" — the labels print "Price: Nil", and
                     this says HOW MANY, before any paper is used -->
                <div v-if="noPriceCount > 0" class="no-print" style="padding:8px 12px;background:#fff7ed;color:#b45309;font-size:13px;border-bottom:1px solid var(--line)">
                    ⚠️ {{ t('bc.noPriceWarn', { n: noPriceCount }) }}
                </div>
                <!--
                    What actually happened, kept ON SCREEN.

                    Every failure here used to be a toast that faded before
                    it could be read, so "render hoye off hoye jai" was all
                    anyone could report and every fix after that was a
                    guess. This log stays put and can be screenshotted.
                -->
                <div v-if="bluetoothLog.length" class="no-print" style="padding:8px 12px;font-size:12px;background:var(--panel);border-bottom:1px solid var(--line)">
                    <div v-for="(line, i) in bluetoothLog" :key="i" :style="'color:' + (line.bad ? 'var(--rose)' : 'var(--mut)')">{{ line.text }}</div>
                </div>
                <!-- Phase 41 — browser print-dialog settings that make the
                     38×25 output land exactly; screen-only, never printed -->
                <div v-if="thermal" class="no-print" style="padding:8px 12px;font-size:12px;color:var(--mut);background:var(--panel);border-bottom:1px solid var(--line)">
                    {{ t('bc.thermalPrintHint', { w: sizeMm.width, h: sizeMm.height }) }}
                </div>
                <div class="label-grid">
                    <BarcodeLabelCard
                        v-for="(p, i) in shownLabels" :key="i + ':' + (p.scanCode || p.barcode || '')"
                        :label="p" :shop="shop" :settings="settings" :dom-id="'barcode-svg-' + i"
                        @rendered="onLabelRendered"
                    />
                </div>
                <!-- what the cap is holding back, and that it changes
                     nothing about what prints -->
                <div v-if="labels.length > shownLabels.length" class="no-print" style="padding:14px;text-align:center;font-size:13px;color:var(--mut)">
                    {{ t('bc.previewCapped', { shown: shownLabels.length, total: labels.length }) }}
                    <button class="btn sm ghost" style="width:auto;padding:6px 12px;margin-top:8px" @click="expandAll">
                        {{ t('bc.previewShowAll') }}
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
#label-print-overlay { position: fixed; inset: 0; background: #fff; z-index: 200; overflow-y: auto; }
.label-grid { display: grid; gap: 8px; padding: 12px; }

/* An article SKU is a chain of hyphenated segments
   (O-B-20034-YXXZ-GOLDEN), and every hyphen is a break opportunity — in a
   flex column squeezed by the copies box on the right it broke at all of
   them at once, one segment per line, and the row grew several lines
   tall. min-width:0 is what lets the column actually shrink instead of
   forcing its content to wrap; the rest keeps the code on one line and
   trims the tail, which is enough to recognise it by. */
.row .mid { min-width: 0; }
.row .mid .sku { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }

/* How many of this label to print. The − / + buttons are here because the
   count is nearly always nudged by one or two, and on a phone that beat
   clearing the box and retyping — which is also what used to break: an
   empty box is still a selected row (see isSelected). */
.copies { display: flex; align-items: center; gap: 2px; }
.copies input {
    width: 54px; padding: 7px 4px; text-align: center; margin: 0;
    -moz-appearance: textfield; appearance: textfield;
}
.copies input::-webkit-outer-spin-button,
.copies input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.copies-step {
    width: 32px; height: 34px; flex: 0 0 auto;
    border: 1px solid var(--line); border-radius: 8px;
    background: var(--card); color: var(--ink);
    font-size: 17px; line-height: 1; cursor: pointer;
}
.copies-step:active { background: var(--line); }

@media print {
    #app { display: none !important; }
    .label-grid { display: block; padding: 0; }
    .label-card {
        box-sizing: border-box; border: none; border-radius: 0; padding: 1.5mm;
        page-break-after: always; break-after: page;
    }
    .label-card:last-child { page-break-after: auto; break-after: auto; }
}
</style>
