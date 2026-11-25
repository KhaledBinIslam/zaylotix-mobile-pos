import { beforeEach, describe, expect, it, vi } from 'vitest';

// The slow drawing step and the share call are the two things that must be kept apart:
// drawing happens before the tap, the tap only shares.
const calls = { build: 0, share: 0, order: [] };
let buildImpl;
let shareImpl;
vi.mock('@/support/bluetoothMemoPrint', () => ({
    buildMemoShare: (...a) => { calls.build++; calls.order.push('build'); return buildImpl(...a); },
    shareMemoFile: (...a) => { calls.share++; calls.order.push('share'); return shareImpl(...a); },
}));

import { useMemoBluetooth } from '../useMemoBluetooth';

const payload = { files: ['png'], text: '{}', title: 't' };
const make = () => useMemoBluetooth({ getEl: () => ({}), getPaper: () => '80mm', getBarcode: () => 'INV-7' });

beforeEach(() => {
    calls.build = 0; calls.share = 0; calls.order = [];
    buildImpl = async () => payload;
    shareImpl = async () => {};
});

describe('useMemoBluetooth', () => {
    it('draws the receipt ahead, so the tap does no drawing before it shares', async () => {
        const bt = make();
        await bt.prepare();
        expect(bt.ready.value).toBe(true);
        calls.order = [];

        await bt.print();

        expect(calls.build).toBe(1);             // only the early build — none on the tap
        expect(calls.order).toEqual(['share']);
        expect(bt.status.value).toContain('Zaylotix Printer');
    });

    it('preparing the same receipt twice draws it once', async () => {
        const bt = make();
        await Promise.all([bt.prepare(), bt.prepare()]);
        expect(calls.build).toBe(1);
    });

    it('if the early draw failed, the tap still works by drawing on the spot', async () => {
        buildImpl = vi.fn()
            .mockRejectedValueOnce(new Error('canvas blew up'))
            .mockResolvedValue(payload);
        const bt = make();
        await bt.prepare().catch(() => {});
        expect(bt.ready.value).toBe(true);       // the button must never stay disabled forever

        await bt.print();

        expect(calls.share).toBe(1);
        expect(bt.status.value).not.toContain('❌');
    });

    it('a dismissed share sheet is reported on screen, naming the error and the app-missing hint', async () => {
        const bt = make();
        await bt.prepare();
        shareImpl = async () => { const e = new Error('x'); e.name = 'AbortError'; throw e; };

        await bt.print();

        expect(bt.status.value).toContain('AbortError');
        expect(bt.status.value).toContain('Zaylotix Printer');
        expect(bt.printing.value).toBe(false);
    });

    it('any other failure shows its real name and message (not a toast that fades)', async () => {
        const bt = make();
        await bt.prepare();
        shareImpl = async () => { const e = new Error('Must be handling a user gesture'); e.name = 'NotAllowedError'; throw e; };

        await bt.print();

        expect(bt.status.value).toBe('❌ NotAllowedError: Must be handling a user gesture');
    });

    it('reset forgets the drawn receipt so a different sale is never printed from a stale picture', async () => {
        const bt = make();
        await bt.prepare();
        bt.reset();
        expect(bt.ready.value).toBe(false);

        await bt.prepare();
        expect(calls.build).toBe(2);
    });
});
