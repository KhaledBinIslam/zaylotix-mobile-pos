import { ref, computed } from 'vue';
import { enqueueSale, removeFromQueue, markFailed, retryEntry, pendingCount, failedCount } from '@/support/offlineQueue';
import { kvGet, kvSet, KEYS } from '@/support/offlineDb';

/**
 * Generic offline queue for a SEQUENCE of order-dependent mutating requests
 * — Restaurant's "add item / add another item / bill" isn't one flat
 * payload like a POS checkout, it's several separate calls that must apply
 * in the order they were made (billing before an earlier add-item synced
 * would bill an order missing that item). Reuses offlineQueue.js's pure
 * functions as-is (they only ever assumed "a payload with a status", never
 * anything POS-specific) against a SEPARATE IndexedDB key, so this can
 * never interact with or corrupt the POS sale queue.
 *
 * Scope, deliberately: this only helps CONTINUE an order that already
 * exists (was opened while online, so it already has a real id from the
 * server) — starting a brand-new order from zero while fully offline isn't
 * supported here, since there's no order id yet to attach anything to.
 */
const queue = ref([]);
const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const syncing = ref(false);
let loaded = false;
let listenersBound = false;

async function persist() {
    await kvSet(KEYS.ACTION_QUEUE, JSON.parse(JSON.stringify(queue.value)));
}

async function ensureLoaded() {
    if (loaded) return;
    loaded = true;
    queue.value = await kvGet(KEYS.ACTION_QUEUE, []);
}

/**
 * Replays queued actions strictly in the order they were queued, and stops
 * at the FIRST one that the server actually rejects (not just a network
 * hiccup) — e.g. an item that's out of stock by sync time, or an order
 * that got billed/cancelled from another device in the meantime. Anything
 * queued after a failed entry is deliberately left untouched rather than
 * risk e.g. billing an order that's missing an item whose add-item call
 * never actually landed. The cashier reviews the failure (same failed-
 * queue UI as the POS sale queue) and retries or discards it before the
 * rest of that queue can continue.
 */
async function trySync() {
    if (syncing.value || !isOnline.value) return;
    await ensureLoaded();
    const pending = queue.value.filter((s) => s.status === 'pending');
    if (!pending.length) return;

    syncing.value = true;
    try {
        for (const entry of pending) {
            try {
                const res = await fetch(entry.payload.url, {
                    method: entry.payload.method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(entry.payload.body || {}),
                });

                if (res.ok) {
                    queue.value = removeFromQueue(queue.value, entry.id);
                } else {
                    const data = await res.json().catch(() => ({}));
                    const message = data.message || Object.values(data.errors || {})[0]?.[0] || 'সিঙ্ক ব্যর্থ হয়েছে।';
                    queue.value = markFailed(queue.value, entry.id, message);
                    await persist();
                    break; // stop — don't risk a later action applying out of order
                }
            } catch {
                break; // still offline — leave the rest pending for the next 'online' event
            }
            await persist();
        }
    } finally {
        syncing.value = false;
    }
}

function bindListeners() {
    if (listenersBound || typeof window === 'undefined') return;
    listenersBound = true;
    window.addEventListener('online', () => {
        isOnline.value = true;
        trySync();
    });
    window.addEventListener('offline', () => {
        isOnline.value = false;
    });
}

export function useOfflineActionQueue() {
    bindListeners();
    ensureLoaded().then(() => {
        if (isOnline.value) trySync();
    });

    async function queueAction(url, method, body) {
        await ensureLoaded();
        queue.value = enqueueSale(queue.value, { url, method, body });
        await persist();
        return queue.value[queue.value.length - 1];
    }

    async function retry(id) {
        queue.value = retryEntry(queue.value, id);
        await persist();
        trySync();
    }

    async function discard(id) {
        queue.value = removeFromQueue(queue.value, id);
        await persist();
    }

    return {
        isOnline,
        syncing,
        queue,
        pendingCount: computed(() => pendingCount(queue.value)),
        failedCount: computed(() => failedCount(queue.value)),
        queueAction,
        trySync,
        retry,
        discard,
    };
}
