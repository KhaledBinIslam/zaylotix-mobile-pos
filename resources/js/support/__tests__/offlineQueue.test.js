import { describe, expect, it } from 'vitest';
import { enqueueSale, removeFromQueue, markFailed, retryEntry, pendingCount, failedCount } from '../offlineQueue.js';

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
});
