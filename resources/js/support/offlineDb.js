// Thin generic IndexedDB key-value persistence, shared by the offline sale
// queue and the offline product/price cache — a shop only ever stores a
// handful of small records this way, so one whole-value get/put per key is
// simpler and safer than per-item object-store records with cursors, with
// no real cost at this scale.

const DB_NAME = 'zaylotix-offline';
const STORE = 'kv';

export const KEYS = {
    SALE_QUEUE: 'sale-queue',
    PRODUCT_CACHE: 'product-cache',
};

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            if (!req.result.objectStoreNames.contains(STORE)) {
                req.result.createObjectStore(STORE);
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
