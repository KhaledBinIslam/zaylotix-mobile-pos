import { ref } from 'vue';
import { buildMemoShare, shareMemoFile } from '@/support/bluetoothMemoPrint';

/**
 * Bluetooth receipt print, built so the tap only has to SHARE.
 *
 * navigator.share() needs a live user gesture and Chrome Android drops it a
 * few seconds after the tap. The old receipt path loaded html2canvas and
 * jsbarcode and drew the memo AFTER the tap, so on a slow phone the share
 * was refused or the share sheet never came up properly — exactly the
 * failure the label page already fixed by building the sheet ahead
 * (BarcodeLabels `prepareSheetAhead`). So: `prepare()` draws the receipt
 * as soon as it is on screen, and `print()` just hands the finished file
 * over. If the early build failed, `print()` builds on the spot as before.
 *
 * `status` is a persistent line shown under the buttons. A toast fades
 * before anyone can read it — which is how "nothing happened" became the
 * only report available — so the outcome (and the error's real name) stays
 * on screen instead.
 *
 * The three callbacks are read at call time, so each page just passes its
 * own refs (sale, paper width) without this file knowing about them.
 */
export function useMemoBluetooth({ getEl, getPaper, getBarcode }) {
    const ready = ref(false);
    const printing = ref(false);
    const status = ref('');
    const failed = ref(false);
    let prepared = null;
    let preparedKey = null;

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function waitForEl() {
        // the receipt sits inside a Sheet that animates in, and ReceiptMemo
        // draws its own barcode SVG on mount — give both a moment to exist
        for (let i = 0; i < 20; i++) {
            const el = getEl();
            if (el) {
                await sleep(350);

                return getEl() || el;
            }
            await sleep(100);
        }

        return null;
    }

    async function build() {
        const el = await waitForEl();
        if (!el) throw new Error('বিলের ছবি পাওয়া যায়নি।');

        return buildMemoShare(el, getPaper(), getBarcode(), (stage) => { status.value = '⏳ ' + stage; });
    }

    /** Draw the receipt now so the tap needs no work. Safe to call repeatedly: one build per receipt. */
    function prepare() {
        const key = String(getBarcode() ?? '') + '|' + String(getPaper() ?? '');
        if (prepared && preparedKey === key) return prepared;
        preparedKey = key;
        ready.value = false;
        status.value = '';
        // ready flips inside the promise itself, so whoever awaits it already sees the button enabled
        prepared = build().then(
            (share) => { if (preparedKey === key) { status.value = ''; failed.value = false; } return share; },
            (e) => {
                // say what stalled/failed right where the cashier looks; the tap will simply try again
                if (preparedKey === key) {
                    status.value = '❌ ' + (e?.message || String(e)) + ' — "আবার চেষ্টা" চাপুন।';
                    failed.value = true;
                }
                throw e;
            },
        ).finally(() => {
            if (preparedKey === key) ready.value = true;
        });
        prepared.catch(() => {}); // a failure is handled at print time; never an unhandled rejection

        return prepared;
    }

    /** Forget the drawn receipt (the sheet closed, or a different sale is showing). */
    function reset() {
        prepared = null;
        preparedKey = null;
        ready.value = false;
        failed.value = false;
        status.value = '';
    }

    async function print() {
        if (printing.value) return;
        // the drawing failed or stalled: this tap is a RETRY of the drawing, not a share. A share after a
        // slow draw would be refused anyway (the tap's permission is gone), so draw again, then the next
        // tap shares.
        if (failed.value) {
            prepared = null;
            preparedKey = null;
            prepare();
            return;
        }
        printing.value = true;
        status.value = '';
        try {
            // already finished in the normal case, so the tap's permission is still live for share()
            let share = null;
            try {
                share = await (prepared ?? prepare());
            } catch (e) {
                share = null;
            }
            if (!share) share = await build();

            await shareMemoFile(share);
            status.value = '✅ Share খুলেছে — তালিকা থেকে "Zaylotix Printer" বেছে নিন।';
        } catch (e) {
            if (e?.name === 'AbortError') {
                // either the share sheet was dismissed, or Chrome refused a gesture that had expired — say both, name the error
                status.value = 'Share বন্ধ হয়েছে (AbortError)। তালিকায় "Zaylotix Printer" না থাকলে নিচের লিংক থেকে অ্যাপ ইনস্টল করুন। তালিকা এসে থাকলে আবার চেপে চেষ্টা করুন।';
            } else {
                status.value = '❌ ' + (e?.name || 'Error') + ': ' + (e?.message || String(e));
            }
            // a failed early build must not poison the next tap
            prepared = null;
            preparedKey = null;
        } finally {
            printing.value = false;
        }
    }

    return { ready, printing, failed, status, prepare, reset, print };
}
