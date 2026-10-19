import { describe, expect, it } from 'vitest';
import { enqueueSale, removeFromQueue, markFailed, markShopMismatch, retryEntry, remapQueuedItem, dropQueuedItem, pendingCount, failedCount, canDiscardEntry, offlineSyncFailureKind } from '../offlineQueue.js';

describe('offlineQueue', () => {
    it('enqueues a sale as pending', () => {
        const queue = enqueueSale([], { items: [{ product_id: 1, qty: 2 }] });
        expect(queue).toHaveLength(1);
        expect(queue[0].status).toBe('pending');
        expect(queue[0].payload.items[0].product_id).toBe(1);
        expect(queue[0].id).toBeTruthy();
    });

    it('removes an entry by id once synced', () => {
        let queue = enqueueSale([], { items: [] });
        const id = queue[0].id;
        queue = enqueueSale(queue, { items: [] });

        queue = removeFromQueue(queue, id);

        expect(queue).toHaveLength(1);
        expect(queue[0].id).not.toBe(id);
    });

    it('marks an entry failed with an error message without dropping it', () => {
        let queue = enqueueSale([], { items: [] });
        const id = queue[0].id;

        queue = markFailed(queue, id, 'পর্যাপ্ত স্টক নেই');

        expect(queue).toHaveLength(1);
        expect(queue[0].status).toBe('failed');
        expect(queue[0].error).toBe('পর্যাপ্ত স্টক নেই');
    });

    it('retrying a failed entry flips it back to pending and clears the error', () => {
        let queue = enqueueSale([], { items: [] });
        const id = queue[0].id;
        queue = markFailed(queue, id, 'network error');

        queue = retryEntry(queue, id);

        expect(queue[0].status).toBe('pending');
        expect(queue[0].error).toBeNull();
    });

    it('counts pending and failed entries separately', () => {
        let queue = enqueueSale([], { items: [] });
        queue = enqueueSale(queue, { items: [] });
        const failedId = queue[0].id;
        queue = markFailed(queue, failedId, 'error');

        expect(pendingCount(queue)).toBe(1);
        expect(failedCount(queue)).toBe(1);
    });

    it('each queued entry gets a unique id', () => {
        let queue = enqueueSale([], { items: [] });
        queue = enqueueSale(queue, { items: [] });

        expect(queue[0].id).not.toBe(queue[1].id);
    });

    // Phase 1.2 — see docs/FIX_PLAN.md: the entry's own id must be the
    // payload's client_uuid when present, so a retried/synced entry always
    // replays under the same idempotency key the server checks.
    it('reuses the payload client_uuid as the entry id when present', () => {
        const queue = enqueueSale([], { client_uuid: 'fixed-uuid-123', items: [] });

        expect(queue[0].id).toBe('fixed-uuid-123');
    });

    // Phase 11 (offline-sync "product not found" fix) — see docs/FIX_PLAN.md.
    it('marks a permanent failure with the problem items the server identified', () => {
        let queue = enqueueSale([], { items: [{ product_id: 5 }] });
        const id = queue[0].id;

        queue = markFailed(queue, id, 'পণ্য পাওয়া যায়নি', 'permanent', [{ product_id: 5, product_name: 'পুরনো পণ্য' }]);

        expect(queue[0].status).toBe('failed');
        expect(queue[0].failureKind).toBe('permanent');
        expect(queue[0].problemItems).toEqual([{ product_id: 5, product_name: 'পুরনো পণ্য' }]);
    });

    it('marks a shop mismatch with its own distinct status, separate from a product problem', () => {
        let queue = enqueueSale([], { items: [], shop_name: 'দোকান A' });
        const id = queue[0].id;

        queue = markShopMismatch(queue, id, 'ভুল দোকান', 'দোকান A');

        expect(queue[0].status).toBe('shop_mismatch');
        expect(queue[0].queuedForShopName).toBe('দোকান A');
        expect(failedCount(queue)).toBe(1); // counts toward the same "needs attention" badge as a plain failure
    });

    it('remapping a problem item to a different product id fixes it in place and re-queues the whole entry', () => {
        let queue = enqueueSale([], { items: [{ product_id: 5, qty: 2 }, { product_id: 9, qty: 1 }] });
        const id = queue[0].id;
        queue = markFailed(queue, id, 'পণ্য পাওয়া যায়নি', 'permanent', [{ product_id: 5, product_name: 'পুরনো পণ্য' }]);

        queue = remapQueuedItem(queue, id, 5, 42, 'নতুন পণ্য');

        expect(queue[0].status).toBe('pending');
        expect(queue[0].problemItems).toEqual([]);
        expect(queue[0].payload.items).toEqual([
            { product_id: 42, qty: 2, product_name: 'নতুন পণ্য' },
            { product_id: 9, qty: 1 },
        ]);
    });

    it('dropping a problem item removes only that line and keeps the rest of the bill', () => {
        let queue = enqueueSale([], { items: [{ product_id: 5, qty: 2 }, { product_id: 9, qty: 1 }] });
        const id = queue[0].id;
        queue = markFailed(queue, id, 'পণ্য পাওয়া যায়নি', 'permanent', [{ product_id: 5, product_name: 'পুরনো পণ্য' }]);

        queue = dropQueuedItem(queue, id, 5);

        expect(queue[0].status).toBe('pending');
        expect(queue[0].payload.items).toEqual([{ product_id: 9, qty: 1 }]);
    });

    // Phase 12 (docs/FIX_PLAN.md) — local-only attribution, used by
    // canDiscardEntry() below; never sent to the server.
    it('records who queued a sale when given', () => {
        const queue = enqueueSale([], { items: [] }, { userId: 7, userName: 'Karim' });

        expect(queue[0].queuedByUserId).toBe(7);
        expect(queue[0].queuedByUserName).toBe('Karim');
    });

    it('defaults attribution to null when no meta is given (an older cached client)', () => {
        const queue = enqueueSale([], { items: [] });

        expect(queue[0].queuedByUserId).toBeNull();
    });

    describe('canDiscardEntry', () => {
        const entry = { queuedByUserId: 7 };

        it('allows anyone with the shop\'s return/void permission, regardless of who queued it', () => {
            expect(canDiscardEntry(entry, true, 99)).toBe(true);
        });

        it('allows the cashier who queued it themselves, without that permission', () => {
            expect(canDiscardEntry(entry, false, 7)).toBe(true);
        });

        it('denies a different cashier without the permission', () => {
            expect(canDiscardEntry(entry, false, 99)).toBe(false);
        });

        it('denies when there is no logged-in user id to compare at all', () => {
            expect(canDiscardEntry(entry, false, null)).toBe(false);
        });

        it('denies a legacy entry with no attribution, even for the cashier who likely queued it', () => {
            expect(canDiscardEntry({ queuedByUserId: null }, false, 7)).toBe(false);
        });
    });

    // see docs/SESSION_FIX.md — this is the fix for the earlier unexplained
    // "sale(s) failed to sync" report with nothing in the server log
    describe('offlineSyncFailureKind', () => {
        it('treats a 401 (real/transient logout) as transient, not a sale-content problem', () => {
            expect(offlineSyncFailureKind(401)).toBe('transient');
        });

        it('treats a 419 (stale CSRF token) as transient', () => {
            expect(offlineSyncFailureKind(419)).toBe('transient');
        });

        it('treats any 5xx as transient (the server\'s own problem)', () => {
            expect(offlineSyncFailureKind(500)).toBe('transient');
            expect(offlineSyncFailureKind(503)).toBe('transient');
        });

        it('treats any other 4xx (stock gone, bad product, validation) as permanent', () => {
            expect(offlineSyncFailureKind(400)).toBe('permanent');
            expect(offlineSyncFailureKind(404)).toBe('permanent');
            expect(offlineSyncFailureKind(422)).toBe('permanent');
        });
    });
});
