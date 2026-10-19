// Pure queue-manipulation functions — no IndexedDB/DOM here, so these are
// unit-testable the same way scannerBuffer.js is. offlineDb.js is the thin
// persistence layer that actually stores whatever array these return.

export function enqueueSale(queue, payload, meta = {}) {
    // the payload already carries its own idempotency key (client_uuid —
    // see PosController::performCheckout()'s matching check), generated once
    // per checkout attempt in Pos/Index.vue's buildCheckoutPayload() —
    // reusing it as this entry's local id too means a sale that's retried
    // after a sync failure replays with the exact same key every time,
    // instead of a fresh one that would just create a duplicate sale.
    const id = payload?.client_uuid || ((typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
    const entry = {
        id,
        payload,
        status: 'pending', // 'pending' | 'failed' | 'shop_mismatch'
        error: null,
        // Local-only attribution (never sent to the server, which has no
        // record of this sale at all until it syncs) — see
        // docs/FIX_PLAN.md Phase 12: the pending-sales sheet only offers
        // "discard" on a cashier's OWN queued sale unless they also hold
        // the shop's return/void permission.
        queuedByUserId: meta.userId ?? null,
        queuedByUserName: meta.userName ?? null,
        // 'transient' (network/5xx — safe to auto-retry as-is) or
        // 'permanent' (a real business rejection — resubmitting the exact
        // same payload will fail identically forever; see docs/FIX_PLAN.md).
        // null while pending/never-yet-attempted.
        failureKind: null,
        // Per-line problems the server identified (e.g. a product that
        // couldn't be resolved), so the resolution sheet can offer a fix
        // for each one by name instead of one opaque message for the
        // whole bill. [{product_id, product_name}]
        problemItems: [],
        queuedForShopName: payload?.shop_name || null,
        queuedAt: Date.now(),
    };
    return [...queue, entry];
}

export function removeFromQueue(queue, id) {
    return queue.filter((s) => s.id !== id);
}

// kind: 'transient' | 'permanent'. A shop-mismatch failure uses the
// dedicated 'shop_mismatch' status instead (see markShopMismatch) since
// it isn't a per-item problem and needs its own distinct resolution UI.
export function markFailed(queue, id, error, kind = 'permanent', problemItems = []) {
    return queue.map((s) => (s.id === id ? { ...s, status: 'failed', error, failureKind: kind, problemItems } : s));
}

export function markShopMismatch(queue, id, error, queuedForShopName) {
    return queue.map((s) => (s.id === id ? { ...s, status: 'shop_mismatch', error, queuedForShopName: queuedForShopName || s.queuedForShopName } : s));
}

// a failed entry (e.g. stock ran out by the time it synced) can be retried
// once the cashier has resolved it (adjusted stock, etc.) — flips it back
// to 'pending' so the next auto-sync picks it up again. Pointless (and
// guaranteed to fail identically) for a 'permanent' failure whose payload
// hasn't actually changed — see resolveItem/dropItem below for the real
// fix path for those; the UI only offers this retry button for
// 'transient' failures.
export function retryEntry(queue, id) {
    return queue.map((s) => (s.id === id ? { ...s, status: 'pending', error: null, failureKind: null } : s));
}

// Resolution actions — mutate the STORED payload (not just a one-off
// request), so the fix sticks and the next sync attempt uses the
// corrected cart, never silently discarding the rest of a multi-item bill
// over one bad line.
export function remapQueuedItem(queue, id, productId, newProductId, newProductName) {
    return queue.map((s) => {
        if (s.id !== id) return s;
        const items = s.payload.items.map((item) => (item.product_id === productId
            ? { ...item, product_id: newProductId, product_name: newProductName || item.product_name }
            : item));
        return { ...s, payload: { ...s.payload, items }, status: 'pending', error: null, failureKind: null, problemItems: [] };
    });
}

export function dropQueuedItem(queue, id, productId) {
    return queue.map((s) => {
        if (s.id !== id) return s;
        const items = s.payload.items.filter((item) => item.product_id !== productId);
        return { ...s, payload: { ...s.payload, items }, status: 'pending', error: null, failureKind: null, problemItems: [] };
    });
}

// "Can this person discard this specific queued entry" (docs/FIX_PLAN.md
// Phase 12): anyone with the shop's return/void permission can discard
// any stuck entry; a cashier without it can only discard their OWN.
// Pure so AppLayout.vue's template logic is unit-testable without
// mounting the component.
export function canDiscardEntry(entry, hasReturnsPermission, currentUserId) {
    if (hasReturnsPermission) return true;
    return !!currentUserId && entry.queuedByUserId === currentUserId;
}

export function pendingCount(queue) {
    return queue.filter((s) => s.status === 'pending').length;
}

export function failedCount(queue) {
    return queue.filter((s) => s.status === 'failed' || s.status === 'shop_mismatch').length;
}
