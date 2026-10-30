import { describe, expect, it } from 'vitest';
import { buildShareMetadata, canShareLabelFiles } from '../bluetoothPrintShare';

describe('buildShareMetadata', () => {
    it('builds the printer-helper JSON from a thermal 38x25 shop setting', () => {
        const json = buildShareMetadata({ size: '38x25', label_gap_mm: 2 });
        expect(JSON.parse(json)).toEqual({ widthMm: 38, heightMm: 25, gapMm: 2 });
    });

    it('follows a custom size and gap', () => {
        const json = buildShareMetadata({ size: 'custom', custom_width_mm: 50, custom_height_mm: 30, label_gap_mm: 3 });
        expect(JSON.parse(json)).toEqual({ widthMm: 50, heightMm: 30, gapMm: 3 });
    });

    it('defaults gap to 2mm when the shop never set one', () => {
        const json = buildShareMetadata({ size: '40x30' });
        expect(JSON.parse(json).gapMm).toBe(2);
    });
});

describe('canShareLabelFiles', () => {
    it('false when navigator.share is missing entirely', () => {
        expect(canShareLabelFiles({})).toBe(false);
    });

    it('false when canShare exists but reports files unsupported', () => {
        const nav = { share: () => {}, canShare: () => false };
        expect(canShareLabelFiles(nav)).toBe(false);
    });

    it('true when canShare reports file support', () => {
        const nav = { share: () => {}, canShare: (data) => !!data.files };
        expect(canShareLabelFiles(nav)).toBe(true);
    });

    it('false (not throwing) when canShare itself throws', () => {
        const nav = { share: () => {}, canShare: () => { throw new Error('nope'); } };
        expect(canShareLabelFiles(nav)).toBe(false);
    });
});
