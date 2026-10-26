// Thin generic IndexedDB key-value persistence, shared by the offline sale
// queue and a few other small records — a shop only ever stores a handful
// of these this way, so one whole-value get/put per key is simpler and
// safer than per-item object-store records with cursors, with no real cost
// at this scale.
//
// The PRODUCTS object store (v2, added for the POS catalog scale fix — see
// docs/FIX_PLAN.md's design note) is the one exception: a shop over
// config('pos.full_catalog_limit') can have thousands of products, so that
// one genuinely needs real per-row records with an index, not a single
// growing blob under one kv key — see putProducts()/searchProductsOffline()/
// findProductByBarcode() below. KEYS.PRODUCT_CACHE above is now retired in
// favor of the PRODUCTS store; kept only so an old cached value (if any)
// is harmlessly ignored rather than crashing a read.

const DB_NAME = 'zaylotix-offline';
const DB_VERSION = 2;
const STORE = 'kv';
const PRODUCTS_STORE = 'products';

export const KEYS = {
    SALE_QUEUE: 'sale-queue',
    PRODUCT_CACHE: 'product-cache',
    LAST_ONLINE_AT: 'last-online-at',
    ACTION_QUEUE: 'action-queue',
    // Local→real invoice number mapping, written once per successful sync
    // (see useOfflineSync.js's trySync()) — lets a cashier who printed/
    // shared an "OFF-XXXXXXXX" placeholder receipt before sync later find
    // out what it actually became (docs/FIX_PLAN.md Phase 12).
    SYNCED_MAP: 'synced-invoice-map',
};

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            if (!req.result.objectStoreNames.contains(STORE)) {
                req.result.createObjectStore(STORE);
            }
            if (!req.result.objectStoreNames.contains(PRODUCTS_STORE)) {
                const store = req.result.createObjectStore(PRODUCTS_STORE, { keyPath: 'id' });
                store.createIndex('barcode', 'barcode', { unique: false });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

export async function kvGet(key, fallback) {
    if (typeof indexedDB === 'undefined') return fallback;
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).get(key);
            req.onsuccess = () => resolve(req.result === undefined ? fallback : req.result);
            req.onerror = () => reject(req.error);
        });
    } catch {
        return fallback;
    }
}

export async function kvSet(key, value) {
    if (typeof indexedDB === 'undefined') return;
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(value, key);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    } catch {
        // best-effort — if IndexedDB is unavailable/blocked (private mode,
        // etc.), callers just don't get offline persistence for this tab
    }
}

/**
 * Bulk-upserts one background-sync chunk (useOfflineCatalog.js) into the
 * PRODUCTS store — one transaction per chunk, not one per row, so a
 * 10,000-SKU catalog's sync doesn't mean 10,000 separate IndexedDB writes.
 */
export async function putProducts(products) {
    if (typeof indexedDB === 'undefined' || !products?.length) return;
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(PRODUCTS_STORE, 'readwrite');
            const store = tx.objectStore(PRODUCTS_STORE);
            for (const p of products) store.put(p);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    } catch {
        // best-effort, same reasoning as kvSet()
    }
}

/**
 * Offline fallback for Pos/Index.vue's `filtered` computed — same exact
 * predicate (name/name_en/barcode/generic_name, case-insensitive except
 * barcode, plus an optional category filter) so a cashier never sees a
 * different result set online vs. offline. Reads the whole store and
 * filters in JS rather than a cursor query — simplest correct option, and
 * fast enough for this since it only ever runs offline, with no network
 * latency to compete with.
 */
export async function searchProductsOffline(query, categoryId) {
    if (typeof indexedDB === 'undefined') return [];
    try {
        const db = await openDb();
        const all = await new Promise((resolve, reject) => {
            const tx = db.transaction(PRODUCTS_STORE, 'readonly');
            const req = tx.objectStore(PRODUCTS_STORE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });

        const q = (query || '').toLowerCase();
        return all.filter((p) => (
            (!query
                || p.name?.toLowerCase().includes(q)
                || p.name_en?.toLowerCase().includes(q)
                || (p.barcode || '').includes(query)
                || (p.generic_name || '').toLowerCase().includes(q)
            ) &&
            (!categoryId || categoryId === 'all' || p.category_id === categoryId)
        ));
    } catch {
        return [];
    }
}

/**
 * Offline fallback for a barcode scan — mirrors Pos/Index.vue's own
 * in-memory lookup order (a variant's own barcode first, then the
 * product's). Tries the indexed product-level `barcode` lookup first
 * (O(1)); a variant barcode miss falls back to a full-store scan, which
 * only ever runs offline on an already-local store, not over the network,
 * so the cost is negligible even for a large cached catalog.
 */
export async function findProductByBarcode(code) {
    if (typeof indexedDB === 'undefined') return null;
    try {
        const db = await openDb();
        const byProductBarcode = await new Promise((resolve, reject) => {
            const tx = db.transaction(PRODUCTS_STORE, 'readonly');
            const req = tx.objectStore(PRODUCTS_STORE).index('barcode').get(code);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
        });
        if (byProductBarcode) return { product: byProductBarcode, variantId: null };

        const all = await new Promise((resolve, reject) => {
            const tx = db.transaction(PRODUCTS_STORE, 'readonly');
            const req = tx.objectStore(PRODUCTS_STORE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
        for (const p of all) {
            const variant = p.variants?.find((v) => v.barcode === code);
            if (variant) return { product: p, variantId: variant.id };
        }

        return null;
    } catch {
        return null;
    }
}

/** Resets the offline catalog cache — a shop downgrading below the search threshold, or manual testing/debugging, never needs the old rows lingering. */
export async function clearProductCache() {
    if (typeof indexedDB === 'undefined') return;
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(PRODUCTS_STORE, 'readwrite');
            tx.objectStore(PRODUCTS_STORE).clear();
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    } catch {
        // best-effort, same reasoning as kvSet()
    }
}
