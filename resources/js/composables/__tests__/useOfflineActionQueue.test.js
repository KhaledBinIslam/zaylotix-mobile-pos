import { describe, expect, it, vi, afterEach } from 'vitest';

/**
 * useOfflineActionQueue.js keeps its queue/syncing state at module scope
 * (so it survives Inertia page navigations within a real session) — each
 * test here re-imports the module fresh via vi.resetModules() so that
 * state doesn't leak between tests, and stubs the browser globals the
 * module reaches for (document, fetch) since Vitest's config runs in a
 * plain Node environment with neither available.
 */
async function freshModule() {
    vi.resetModules();
    return import('../useOfflineActionQueue.js');
}

function jsonResponse(body, ok = true) {
    return { ok, json: async () => body };
}

// Stubbed only AFTER the module (and the 'vue' it imports) has finished
// loading — vue's own module-init code reaches for the real `document` at
// import time, and a bare stub without the rest of the Document API
// (createElement, etc.) crashes that if it's already in place beforehand.
// Nothing in this module touches document before trySync() runs, so this
// order is safe: import → stub → use.
function stubCsrfDocument() {
    vi.stubGlobal('document', { querySelector: () => ({ content: 'test-csrf' }) });
}

/**
 * useOfflineActionQueue() itself kicks off an async "sync on mount if
 * online" attempt (against whatever was already loaded from storage) the
 * instant it's called. Forcing isOnline false right away, synchronously,
 * before that attempt's microtask chain gets a chance to run, keeps it
 * from racing against the actions THIS test queues moments later — every
 * test flips it back to true only once it's ready to sync deliberately.
 */
function setUp() {
    const composable = useOfflineActionQueueRef();
    composable.isOnline.value = false;
    return composable;
}

let useOfflineActionQueueRef;

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('useOfflineActionQueue', () => {
    it('queues an action as pending without touching the network', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, queue, pendingCount } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 1 });

        expect(queue.value).toHaveLength(1);
        expect(queue.value[0].status).toBe('pending');
        expect(pendingCount.value).toBe(1);
        expect(fetchMock).not.toHaveBeenCalled(); // queueAction alone must never sync by itself
    });

    it('a server rejection on one entry stops the queue and leaves later entries untouched', async () => {
        // unlike the POS sale queue (useOfflineSync.js), a restaurant action
        // sequence is order-dependent — billing after a dropped "add item"
        // would bill an order that's missing a line, so trySync() must not
        // silently skip ahead past a rejected entry the way the POS queue does
        const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ message: 'পর্যাপ্ত স্টক নেই' }, false));
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, trySync, queue, isOnline } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 5 });
        await queueAction('/app/restaurant/orders/1/bill', 'POST', { payments: [] });
        isOnline.value = true;
        await trySync();

        expect(fetchMock).toHaveBeenCalledTimes(1); // never even attempted the second entry
        expect(queue.value).toHaveLength(2);
        expect(queue.value[0].status).toBe('failed');
        expect(queue.value[0].error).toBe('পর্যাপ্ত স্টক নেই');
        expect(queue.value[1].status).toBe('pending'); // untouched, exactly as queued
    });

    it('a network failure (still offline) stops the queue the same way, without marking anything failed', async () => {
        const fetchMock = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch'));
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, trySync, queue, isOnline } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 1 });
        isOnline.value = true;
        await trySync();

        expect(queue.value).toHaveLength(1);
        expect(queue.value[0].status).toBe('pending'); // left pending, not failed — retried on the next 'online' event
    });

    it('entries succeed and clear in order until one is rejected', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({ ok: true }, true))
            .mockResolvedValueOnce(jsonResponse({ message: 'এই অর্ডারটি আর খোলা নেই।' }, false));
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, trySync, queue, isOnline } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 1 });
        await queueAction('/app/restaurant/orders/1/bill', 'POST', { payments: [] });
        isOnline.value = true;
        await trySync();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(queue.value).toHaveLength(1); // the succeeded add-item was removed
        expect(queue.value[0].status).toBe('failed'); // the bill that followed was rejected
    });

    it('retrying a failed entry flips it back to pending and re-attempts sync', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({ message: 'stock issue' }, false))
            .mockResolvedValueOnce(jsonResponse({ ok: true }, true));
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, trySync, retry, queue, isOnline } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 5 });
        isOnline.value = true;
        await trySync();
        expect(queue.value[0].status).toBe('failed');

        await retry(queue.value[0].id);
        // retry() deliberately fires trySync() without awaiting it (matches
        // production: a retry click shouldn't block the UI) — flush a real
        // tick so that fire-and-forget sync has finished before asserting
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(queue.value).toHaveLength(0); // succeeded on retry, removed
    });

    it('discarding an entry drops it without touching the network', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        ({ useOfflineActionQueue: useOfflineActionQueueRef } = await freshModule());
        stubCsrfDocument();
        const { queueAction, discard, queue } = setUp();

        await queueAction('/app/restaurant/orders/1/items', 'POST', { product_id: 5, qty: 1 });
        const id = queue.value[0].id;
        await discard(id);

        expect(queue.value).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
