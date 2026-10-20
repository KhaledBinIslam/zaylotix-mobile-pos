import { describe, expect, it } from 'vitest';
import { applySegment, daysSince } from '../customerSegments.js';

const customers = [
    { id: 1, name: 'A', total_spent: 100, visits: 1, due: 0, updated_at: new Date().toISOString() },
    { id: 2, name: 'B', total_spent: 500, visits: 9, due: 300, updated_at: new Date().toISOString() },
    { id: 3, name: 'C', total_spent: 50, visits: 20, due: 0, updated_at: new Date(Date.now() - 40 * 86400000).toISOString() },
];

describe('applySegment', () => {
    it('leaves the list as-is (same order) for "all"', () => {
        expect(applySegment(customers, 'all').map((c) => c.id)).toEqual([1, 2, 3]);
    });

    it('sorts by total_spent descending for topSpenders', () => {
        expect(applySegment(customers, 'topSpenders').map((c) => c.id)).toEqual([2, 1, 3]);
    });

    it('sorts by visits descending for mostVisits', () => {
        expect(applySegment(customers, 'mostVisits').map((c) => c.id)).toEqual([3, 2, 1]);
    });

    it('filters to due>0 and sorts descending for highestDue', () => {
        expect(applySegment(customers, 'highestDue').map((c) => c.id)).toEqual([2]);
    });

    it('filters to 30+ days inactive for inactive', () => {
        expect(applySegment(customers, 'inactive').map((c) => c.id)).toEqual([3]);
    });

    it('does not mutate the original array', () => {
        const copy = [...customers];
        applySegment(customers, 'topSpenders');
        expect(customers).toEqual(copy);
    });
});

describe('daysSince', () => {
    it('computes whole days elapsed', () => {
        const tenDaysAgo = new Date(Date.now() - 10 * 86400000).toISOString();
        expect(daysSince(tenDaysAgo)).toBe(10);
    });
});
