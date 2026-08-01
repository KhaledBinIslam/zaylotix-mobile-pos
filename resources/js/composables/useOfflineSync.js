import { ref, computed } from 'vue';
import { enqueueSale, removeFromQueue, markFailed, retryEntry, pendingCount, failedCount } from '@/support/offlineQueue';
import { kvGet, kvSet, KEYS } from '@/support/offlineDb';

// Module-level (not inside the exported function) so every component that
// calls useOfflineSync() shares the exact same reactive queue/online state
// — Inertia navigates between pages without a full reload, so a fresh
// per-component ref would forget "3 sales pending" the moment the cashier
// left the POS page for Stock and came back.
const queue = ref([]);
const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const syncing = ref(false);
let loaded = false;
let listenersBound = false;

async function persist() {
    await kvSet(KEYS.SALE_QUEUE, JSON.parse(JSON.stringify(queue.value)));
}

async function ensureLoaded() {
    if (loaded) return;
    loaded = true;
    queue.value = await kvGet(KEYS.SALE_QUEUE, []);
}

/**
 * Replays every still-pending queued sale against the real checkout
 * endpoint — the exact same one a normal online sale hits, so server-side
 * validation (stock, prescription, etc.) re-runs for real at sync time
 * instead of trusting whatever was true when the cashier tapped checkout
 * offline. A sale that no longer validates (e.g. someone else already sold
 * the last unit while this device was offline) is marked 'failed' with the
 * server's own message and kept in the queue for the cashier to review —
 * never silently dropped, never silently double-sold.
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
                const res = await fetch(route('app.pos.checkout'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(entry.payload),
                });
                const data = await res.json().catch(() => ({}));

                if (res.ok) {
                    queue.value = removeFromQueue(queue.value, entry.id);
                } else {
                    const message = data.message || Object.values(data.errors || {})[0]?.[0] || 'সিঙ্ক ব্যর্থ হয়েছে।';
                    queue.value = markFailed(queue.value, entry.id, message);
                }
            } catch {
                // still offline (or the network dropped mid-sync) — stop
                // here, leave the rest pending for the next 'online' event
                break;
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

export function useOfflineSync() {
    bindListeners();
    ensureLoaded().then(() => {
        if (isOnline.value) trySync();
    });

    async function queueSale(payload) {
        await ensureLoaded();
        queue.value = enqueueSale(queue.value, payload);
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
        queueSale,
        trySync,
        retry,
        discard,
    };
}
