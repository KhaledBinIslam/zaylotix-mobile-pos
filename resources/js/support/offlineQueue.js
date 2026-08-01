// Pure queue-manipulation functions — no IndexedDB/DOM here, so these are
// unit-testable the same way scannerBuffer.js is. offlineDb.js is the thin
// persistence layer that actually stores whatever array these return.

export function enqueueSale(queue, payload) {
    const entry = {
        id: (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
        payload,
        status: 'pending', // 'pending' | 'failed'
        error: null,
        queuedAt: Date.now(),
    };
    return [...queue, entry];
}

export function removeFromQueue(queue, id) {
    return queue.filter((s) => s.id !== id);
}

export function markFailed(queue, id, error) {
    return queue.map((s) => (s.id === id ? { ...s, status: 'failed', error } : s));
}

// a failed entry (e.g. stock ran out by the time it synced) can be retried
// once the cashier has resolved it (adjusted stock, etc.) — flips it back
// to 'pending' so the next auto-sync picks it up again
export function retryEntry(queue, id) {
    return queue.map((s) => (s.id === id ? { ...s, status: 'pending', error: null } : s));
}

export function pendingCount(queue) {
    return queue.filter((s) => s.status === 'pending').length;
}

export function failedCount(queue) {
    return queue.filter((s) => s.status === 'failed').length;
}
