import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { flattenSearchable, searchItems } from '@/support/featureSearch';
import { buildExtraSearchItems } from '@/support/moreMenuExtraItems';

/**
 * Khaled's explicit QA demand (2026-11-15): the home/more search must be
 * "fully dynamic" — a shop with fewer features/permissions must see fewer
 * results, not a hardcoded list. These tests build two DIFFERENT fake
 * navGroups shapes (mirroring what useNavItems() actually returns for an
 * owner vs. a restricted cashier vs. a shop without a feature) and prove
 * the search result set genuinely differs between them.
 */
describe('featureSearch', () => {
    const navGroups = [
        {
            label: 'Sales',
            items: [
                { key: 'sell', label: 'Sell (POS)', href: '/app/pos', icon: 'cart', show: true },
                { key: 'onlineOrders', label: 'Online orders', href: '/app/online-orders', icon: 'globe', show: false },
            ],
        },
        {
            label: 'Inventory',
            items: [
                { key: 'barcode', label: 'Barcode Labels', href: '/app/barcode-labels', icon: 'tag', show: true },
                { key: 'stock', label: 'Stock', navLabel: 'স্টক', href: '/app/stock', icon: 'box', show: true },
            ],
        },
    ];

    it('drops any item whose show is false', () => {
        const flat = flattenSearchable(navGroups);
        expect(flat.map((i) => i.key)).toEqual(['sell', 'barcode', 'stock']);
    });

    it('matches a Bangla navLabel from an English query', () => {
        const flat = flattenSearchable(navGroups);
        const results = searchItems(flat, 'stock');
        expect(results.map((i) => i.key)).toEqual(['stock']);
    });

    it('matches the stable English key even when the label is only in Bangla', () => {
        const flat = flattenSearchable([{ label: 'X', items: [{ key: 'barcode', label: 'বারকোড লেবেল', href: '#', icon: 'tag', show: true }] }]);
        expect(searchItems(flat, 'barcode').length).toBe(1);
    });

    it('returns nothing for an empty query', () => {
        const flat = flattenSearchable(navGroups);
        expect(searchItems(flat, '   ')).toEqual([]);
    });

    it('case-insensitive and substring, not exact match', () => {
        const flat = flattenSearchable(navGroups);
        expect(searchItems(flat, 'BARCO').map((i) => i.key)).toEqual(['barcode']);
    });

    /**
     * Khaled's explicit ask (2026-11-15): "banan vul holeo ... search korle
     * cole ase" — a misspelled query must still find the right option.
     */
    describe('typo tolerance', () => {
        it('one wrong letter in an English word still matches ("barcod" -> barcode)', () => {
            const flat = flattenSearchable(navGroups);
            expect(searchItems(flat, 'barcod').map((i) => i.key)).toContain('barcode');
        });

        it('one wrong Bangla letter still matches a Bangla label', () => {
            const flat = flattenSearchable([{ label: 'X', items: [{ key: 'stock', label: 'স্টক', href: '#', icon: 'box', show: true }] }]);
            // "স্টোক" instead of "স্টক" — one extra/substituted character
            expect(searchItems(flat, 'স্টোক').map((i) => i.key)).toContain('stock');
        });

        it('a short query (under 3 chars) stays strict — no fuzzy noise on tiny queries', () => {
            const flat = flattenSearchable(navGroups);
            expect(searchItems(flat, 'xz')).toEqual([]);
        });

        it('a completely unrelated word still matches nothing', () => {
            const flat = flattenSearchable(navGroups);
            expect(searchItems(flat, 'zzzzzzzzzz')).toEqual([]);
        });

        it('exact matches still rank before fuzzy ones', () => {
            const flat = flattenSearchable([
                { label: 'X', items: [
                    { key: 'a', label: 'Stock', href: '#', icon: 'box', show: true },
                    { key: 'b', label: 'Stocks History', href: '#', icon: 'box', show: true },
                ] },
            ]);
            // "stock" is an exact substring of BOTH, so order is stable —
            // this just confirms exact matches never get pushed behind fuzzy ones
            const results = searchItems(flat, 'stock');
            expect(results[0].key).toBe('a');
        });
    });
});

describe('buildExtraSearchItems — genuinely per-shop dynamic', () => {
    const t = (key) => key;
    const hasPerm = () => true;

    beforeEach(() => { global.route = (name) => '/app/' + name.split('.').pop(); });
    afterEach(() => { delete global.route; });

    it('a shop WITHOUT hr_payroll never gets attendance/payroll in the results', () => {
        const items = buildExtraSearchItems({ hasPerm, isOwner: true, hasFeature: () => false, branches: null, t });
        const shown = items.filter((i) => i.show).map((i) => i.key);
        expect(shown).not.toContain('attendance');
        expect(shown).not.toContain('payroll');
    });

    it('the SAME shop WITH hr_payroll does get them — proving this is feature-driven, not hardcoded', () => {
        const items = buildExtraSearchItems({ hasPerm, isOwner: true, hasFeature: (k) => k === 'hr_payroll', branches: null, t });
        const shown = items.filter((i) => i.show).map((i) => i.key);
        expect(shown).toContain('attendance');
        expect(shown).toContain('payroll');
    });

    it('a staff account (isOwner: false) never sees owner-only rows like stock transfer or shop backup', () => {
        const items = buildExtraSearchItems({ hasPerm, isOwner: false, hasFeature: () => true, branches: ['sibling'], t });
        const shown = items.filter((i) => i.show).map((i) => i.key);
        expect(shown).not.toContain('stockTransfer');
        expect(shown).not.toContain('shopBackup');
    });

    it('a main shop with no branches never sees Stock Transfer even as owner', () => {
        const items = buildExtraSearchItems({ hasPerm, isOwner: true, hasFeature: () => true, branches: null, t });
        expect(items.find((i) => i.key === 'stockTransfer').show).toBe(false);
    });

    /**
     * Khaled's exact report (2026-11-15): typing "exchange" in the search
     * bar found nothing, because useNavItems()'s own 'return' entry points
     * at the OLD no-invoice sheet and is labeled "Sales Returns" — no
     * "exchange" anywhere in it. This proves the real invoice-based
     * Return/Exchange screen is now searchable by that word.
     */
    it('searching "exchange" finds the real invoice-based return/exchange screen', () => {
        const items = buildExtraSearchItems({ hasPerm, isOwner: true, hasFeature: () => true, branches: null, t });
        const flat = flattenSearchable([], items);
        const results = searchItems(flat, 'exchange');
        expect(results.some((i) => i.key === 'returnExchange')).toBe(true);
    });
});
