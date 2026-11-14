import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest';

/**
 * resolveBarcode() real bug: a 2xx check via `res.ok` treated a server's
 * own definitive "not found" (PosController::barcode()'s 404 JSON) the
 * same as a genuine network/server failure, falling through to the
 * offline IndexedDB cache every time — this file's own docblock already
 * promises that fallback is for when "the network itself fails" only.
 * IndexedDB isn't available under Vitest's node environment, so the
 * offline-cache module is mocked rather than exercised for real.
 */

const findProductByBarcode = vi.fn();
vi.mock('@/support/offlineDb', () => ({
    putProducts: vi.fn(),
    searchProductsOffline: vi.fn(),
    findProductByBarcode: (...args) => findProductByBarcode(...args),
}));

// useOfflineCatalog.js only ever reads `isOnline` off this — the real
// module also runs its own background action-queue/auto-sync machinery
// (reading usePage(), touching IndexedDB) that has nothing to do with
// resolveBarcode() and isn't worth dragging in just to stub around.
let isOnlineValue = true;
vi.mock('@/composables/useOfflineSync', () => ({
    useOfflineSync: () => ({ isOnline: { get value() { return isOnlineValue; } } }),
}));

// route() is a global Ziggy helper the real app bootstraps; the module
// only ever calls route('app.pos.barcode', code) here, so the exact URL
// doesn't matter to this test
function stubGlobals() {
    vi.stubGlobal('route', (name, param) => `/app/pos/barcode/${param ?? ''}`);
}

function jsonResponse(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}

async function freshModule() {
    vi.resetModules();
    findProductByBarcode.mockReset();
    return import('../useOfflineCatalog.js');
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    isOnlineValue = true;
});

describe('resolveBarcode', () => {
    beforeEach(stubGlobals);

    it('trusts a 404 as genuinely not-found, without touching the offline cache', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(404, { found: false })));
        const { useOfflineCatalog } = await freshModule();
        const { resolveBarcode } = useOfflineCatalog();

        const result = await resolveBarcode('NOPE-123');

        expect(result).toEqual({ found: false });
        expect(findProductByBarcode).not.toHaveBeenCalled();
    });

    it('still returns the server JSON on a real 200 match', async () => {
        const payload = { found: true, product: { id: 1, name: 'L-10003' } };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, payload)));
        const { useOfflineCatalog } = await freshModule();

        const result = await useOfflineCatalog().resolveBarcode('ANG-L-10003-BK');

        expect(result).toEqual(payload);
        expect(findProductByBarcode).not.toHaveBeenCalled();
    });

    it('falls back to the offline cache on a genuine fetch failure', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network down')));
        const { useOfflineCatalog } = await freshModule();
        findProductByBarcode.mockResolvedValue({ product: { id: 2, name: 'Cached' }, variantId: null });

        const result = await useOfflineCatalog().resolveBarcode('ANY-CODE');

        expect(result).toEqual({ found: true, product: { id: 2, name: 'Cached' }, variant_id: null });
        expect(findProductByBarcode).toHaveBeenCalledWith('ANY-CODE');
    });

    it('falls back to the offline cache on a genuine 500 server error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(500, {})));
        const { useOfflineCatalog } = await freshModule();
        findProductByBarcode.mockResolvedValue(null);

        const result = await useOfflineCatalog().resolveBarcode('ANY-CODE');

        expect(result).toEqual({ found: false });
        expect(findProductByBarcode).toHaveBeenCalledWith('ANY-CODE');
    });

    /**
     * Real bug (Baby Park live, 2026-11-14): one cashier's phone scanned
     * "not found" for an item that scanned fine on another phone, same
     * item, same shop. Root cause: navigator.onLine (surfaced here as
     * useOfflineSync's `isOnline`) is known to get stuck reporting false
     * on some devices even with a perfectly working connection — and this
     * module used to skip the server request entirely whenever it was
     * false, going straight to the (stale, same-shop-only) local cache.
     * resolveBarcode() must always attempt the real request regardless of
     * that flag; only a genuine thrown failure may fall back to the cache.
     */
    it('still attempts the server request even when navigator.onLine reports offline', async () => {
        isOnlineValue = false;
        const payload = { found: true, product: { id: 1, name: 'L-10003' } };
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, payload));
        vi.stubGlobal('fetch', fetchMock);
        const { useOfflineCatalog } = await freshModule();

        const result = await useOfflineCatalog().resolveBarcode('ANG-L-10003-BK');

        expect(fetchMock).toHaveBeenCalled();
        expect(result).toEqual(payload);
        expect(findProductByBarcode).not.toHaveBeenCalled();
    });
});
