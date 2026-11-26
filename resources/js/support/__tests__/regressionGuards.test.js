import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Source-level guards for the bugs that reached Baby Park's counters in
 * November 2026. Each one failed silently in production and was found by a
 * shop owner, not by a test; each guard fails the build the moment the same
 * mistake is written again — by anyone, in any new screen.
 */
const root = join(__dirname, '..', '..'); // resources/js

function sourceFiles(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        if (statSync(p).isDirectory()) {
            if (name === '__tests__' || name === 'node_modules') continue;
            sourceFiles(p, out);
        } else if (/\.(vue|js)$/.test(name)) {
            out.push(p);
        }
    }
    return out;
}

const files = sourceFiles(root).map((p) => ({ p, rel: p.slice(root.length + 1).replaceAll('\\', '/'), src: readFileSync(p, 'utf8') }));

describe('every camera scanner uses the one shared decoder setup', () => {
    // support/scannerConfig.js names why: no format list + 1080p frames made
    // the Exchange / Online Order / Stock / Restaurant scanners open the camera
    // and never read a CODE_128 sticker, while POS (which had the config) did.
    const scanners = files.filter((f) => /new\s+Html5Qrcode\(/.test(f.src));

    it('finds the scanners (guard is not vacuous)', () => {
        expect(scanners.length).toBeGreaterThanOrEqual(5);
    });

    for (const f of scanners) {
        it(`${f.rel} passes scannerFormats() and scannerOptions()`, () => {
            expect(f.src, 'missing the shared format list').toMatch(/scannerFormats\(/);
            expect(f.src, 'missing the shared start options').toMatch(/scannerOptions\(/);
        });
    }
});

describe('every place that deletes through Inertia surfaces a server refusal', () => {
    // Stock page's product delete had no onError: a refusal looked like
    // "nothing happened" and the owner reported "I cannot delete".
    const deleters = files.filter((f) => /router\.delete\(route\('app\.products\.destroy'/.test(f.src));

    it('finds the product delete (guard is not vacuous)', () => {
        expect(deleters.length).toBeGreaterThanOrEqual(1);
    });

    for (const f of deleters) {
        it(`${f.rel}: products.destroy has an onError handler`, () => {
            const at = f.src.search(/router\.delete\(route\('app\.products\.destroy'/);
            const call = f.src.slice(at, at + 600);
            expect(call).toMatch(/onError/);
        });
    }
});

describe('the Return/Exchange camera never lives inside a <Sheet>', () => {
    // Closing a Sheet runs history.back(), which remounts the Inertia page and
    // wiped the picked bill + new exchange items ("goes back to the start").
    it('Returns/Index.vue uses the shared CameraScanner overlay', () => {
        const f = files.find((x) => x.rel === 'Pages/App/Returns/Index.vue');
        expect(f.src).toMatch(/<CameraScanner\b/);
        expect(f.src).not.toMatch(/<Sheet v-model="scannerOpen"/);
    });
});

describe('a new build never waits on a banner nobody taps', () => {
    it('useVersionCheck reloads itself when idle, and only through shouldAutoReload()', () => {
        const f = files.find((x) => x.rel === 'composables/useVersionCheck.js');
        expect(f.src).toMatch(/shouldAutoReload\(/);
        // the safe-moment rule must be the ONLY reload trigger there
        const reloads = f.src.match(/location\.reload\(/g) || [];
        expect(reloads.length).toBe(1);
    });
});

describe('Bluetooth receipt print never draws the receipt after the tap', () => {
    // navigator.share() needs a live tap; drawing the memo (html2canvas + jsbarcode) after it
    // outlasts Chrome's window on a slow phone and the share list never comes up properly.
    it.each(['Pages/App/Pos/Index.vue', 'Pages/App/Clothing/Pos.vue', 'Pages/App/Sales/Show.vue'])(
        '%s draws ahead through useMemoBluetooth and does not build inside the tap',
        (rel) => {
            const f = files.find((x) => x.rel === rel);
            expect(f.src).toMatch(/useMemoBluetooth\(/);
            expect(f.src).not.toMatch(/printMemoViaBluetooth/);
            expect(f.src).toMatch(/<MemoBluetoothNote\b/);
        },
    );
});

describe('closing a sheet from code never remounts the page (billed cart coming back)', () => {
    it('app.js imports the popstate guard before Inertia, and the guard swallows only our own pop', () => {
        const app = files.find((x) => x.rel === 'app.js').src;
        const guardAt = app.indexOf("import './composables/useSheetHistoryDepth'");
        const inertiaAt = app.indexOf("from '@inertiajs/vue3'");
        expect(guardAt).toBeGreaterThan(-1);
        expect(guardAt).toBeLessThan(inertiaAt);

        const dep = files.find((x) => x.rel === 'composables/useSheetHistoryDepth.js').src;
        expect(dep).toMatch(/addEventListener\('popstate', swallowOwnPop, true\)/);
        expect(dep).toMatch(/ownBackPending = true;[\s\S]*history\.back\(\)/);
    });
});

describe('no receipt carries a customer-rating QR', () => {
    // Khaled, 2026-11-26: the "Rate your experience" QR was removed from every bill. A shop that wants a QR uses its
    // own website / Facebook page (settings.show_shop_qr, off by default) — nothing is added on its behalf.
    it('ReceiptMemo never renders or draws a rating QR', () => {
        const memo = files.find((x) => x.rel === 'Components/ReceiptMemo.vue').src;
        expect(memo).not.toMatch(/rating_url/);
        expect(memo).not.toMatch(/Rate your experience/);
        expect(memo).not.toMatch(/memo-rating-qr/);
        expect(memo).toMatch(/show_shop_qr/); // the shop's own QR is still there
    });
});
