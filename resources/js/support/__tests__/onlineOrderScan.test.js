import { describe, expect, it, vi } from 'vitest';
import { resolveScannedCode } from '../onlineOrderScan';

const products = () => [
    {
        id: 869, name: '80001', barcode: '80001-IXXZ',
        variants: [
            { id: 10242, size: '40-41', color: 'WHITE-BLUE', barcode: 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', stock: 6 },
            { id: 10240, size: '36-37', color: 'WHITE-BLUE', barcode: 'CHINA-S-80001-IXXZ-WHITE-BLUE-36-37', stock: 1 },
        ],
        color_barcodes: [{ id: 55, color: 'WHITE-BLUE', barcode: 'CHINA-S-80001-IXXZ-WHITE-BLUE' }],
    },
    { id: 7, name: 'Plain Bag', barcode: 'BAG-7', variants: [], color_barcodes: [] },
];

const neverCalled = () => vi.fn(() => Promise.reject(new Error('lookup must not be called')));

describe('resolveScannedCode — local hits never touch the network', () => {
    it('own-shop ZV code adds that size', async () => {
        const lookup = neverCalled();
        const r = await resolveScannedCode({ code: 'ZV10242', products: products(), lookup });
        expect(r).toMatchObject({ type: 'line', variant: { id: 10242 } });
        expect(lookup).not.toHaveBeenCalled();
    });

    it("a size's own long barcode adds that size", async () => {
        const lookup = neverCalled();
        const r = await resolveScannedCode({ code: 'CHINA-S-80001-IXXZ-WHITE-BLUE-40-41', products: products(), lookup });
        expect(r).toMatchObject({ type: 'line', product: { id: 869 }, variant: { id: 10242 } });
        expect(lookup).not.toHaveBeenCalled();
    });

    it('ZC / colour barcode opens the colour picker', async () => {
        const lookup = neverCalled();
        expect(await resolveScannedCode({ code: 'ZC55', products: products(), lookup })).toMatchObject({ type: 'color', color: 'WHITE-BLUE' });
        expect(await resolveScannedCode({ code: 'CHINA-S-80001-IXXZ-WHITE-BLUE', products: products(), lookup })).toMatchObject({ type: 'color', color: 'WHITE-BLUE' });
        expect(lookup).not.toHaveBeenCalled();
    });

    it('article barcode with sizes opens the size picker; plain product adds a line', async () => {
        const lookup = neverCalled();
        expect(await resolveScannedCode({ code: '80001-IXXZ', products: products(), lookup })).toMatchObject({ type: 'picker', product: { id: 869 } });
        expect(await resolveScannedCode({ code: 'BAG-7', products: products(), lookup })).toMatchObject({ type: 'line', product: { id: 7 }, variant: null });
        expect(lookup).not.toHaveBeenCalled();
    });

    it('a scanner code is matched exactly — no case/space forgiveness', async () => {
        const lookup = vi.fn(() => Promise.resolve({ found: false }));
        const r = await resolveScannedCode({ code: 'china-s-80001-ixxz-white-blue-40-41', products: products(), lookup });
        expect(r).toEqual({ type: 'notFound' });
        expect(lookup).toHaveBeenCalledTimes(1); // local miss -> server asked -> still not found
    });
});

describe('resolveScannedCode — local miss falls back to the server once', () => {
    it("a sibling branch's ZV id resolves to this shop's size via the server", async () => {
        // ZV10239 is the OTHER branch's row for the same size — not in this shop's list
        const serverProduct = { id: 869, name: '80001', variants: [{ id: 10242, size: '40-41', color: 'WHITE-BLUE' }] };
        const lookup = vi.fn(() => Promise.resolve({ found: true, product: serverProduct, variant_id: 10242 }));

        const r = await resolveScannedCode({ code: 'ZV10239', products: products(), lookup });

        expect(lookup).toHaveBeenCalledTimes(1);
        expect(lookup).toHaveBeenCalledWith('ZV10239');
        expect(r).toMatchObject({ type: 'line', product: { id: 869 }, variant: { id: 10242 }, fromServer: true });
    });

    it('server colour match opens the colour picker; server plain product adds a line', async () => {
        const colorLookup = vi.fn(() => Promise.resolve({ found: true, product: { id: 869, variants: [{ id: 1 }] }, color: 'PINK' }));
        expect(await resolveScannedCode({ code: 'ZC999', products: [], lookup: colorLookup })).toMatchObject({ type: 'color', color: 'PINK', fromServer: true });

        const plainLookup = vi.fn(() => Promise.resolve({ found: true, product: { id: 7, variants: [] } }));
        expect(await resolveScannedCode({ code: 'BAG-7', products: [], lookup: plainLookup })).toMatchObject({ type: 'line', product: { id: 7 }, variant: null, fromServer: true });
    });

    it('not found on the server -> notFound; a failing request also degrades to notFound', async () => {
        expect(await resolveScannedCode({ code: 'NO-SUCH', products: products(), lookup: () => Promise.resolve({ found: false }) })).toEqual({ type: 'notFound' });
        expect(await resolveScannedCode({ code: 'NO-SUCH', products: products(), lookup: () => Promise.reject(new Error('offline')) })).toEqual({ type: 'notFound' });
    });

    it('blank input is notFound without a lookup', async () => {
        const lookup = neverCalled();
        expect(await resolveScannedCode({ code: '   ', products: products(), lookup })).toEqual({ type: 'notFound' });
        expect(lookup).not.toHaveBeenCalled();
    });
});

describe('resolveScannedCode — typed input only is forgiving', () => {
    it('lower-case with stray spaces still matches locally when typed', async () => {
        const lookup = neverCalled();
        const r = await resolveScannedCode({ code: ' china-s-80001-ixxz-white-blue-40-41 ', products: products(), lookup, typed: true });
        expect(r).toMatchObject({ type: 'line', variant: { id: 10242 } });
        expect(lookup).not.toHaveBeenCalled();
    });

    it('typed "zv10242" also resolves locally', async () => {
        const lookup = neverCalled();
        expect(await resolveScannedCode({ code: 'zv10242', products: products(), lookup, typed: true })).toMatchObject({ type: 'line', variant: { id: 10242 } });
    });
});
