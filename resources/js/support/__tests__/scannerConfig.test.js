import { describe, it, expect } from 'vitest';
import { scannerFormats, scannerOptions } from '../scannerConfig';

// stand-in for html5-qrcode's enum
const FORMATS = {
    CODE_128: 1, EAN_13: 2, EAN_8: 3, UPC_A: 4, UPC_E: 5, QR_CODE: 6,
    CODE_39: 7, ITF: 8, PDF_417: 9, AZTEC: 10, DATA_MATRIX: 11, MAXICODE: 12,
};

describe('scannerFormats', () => {
    it('asks for only the symbologies this shop actually meets', () => {
        const picked = scannerFormats(FORMATS);
        // CODE_128 is what ArticleBarcode prints — it must be there
        expect(picked).toContain(FORMATS.CODE_128);
        expect(picked).toContain(FORMATS.EAN_13);
        expect(picked).toContain(FORMATS.QR_CODE);
    });

    it('leaves out the rest, which is where the speed comes from', () => {
        const picked = scannerFormats(FORMATS);
        for (const unused of [FORMATS.PDF_417, FORMATS.AZTEC, FORMATS.DATA_MATRIX, FORMATS.MAXICODE]) {
            expect(picked).not.toContain(unused);
        }
        expect(picked.length).toBeLessThan(Object.keys(FORMATS).length);
    });
});

describe('scannerOptions', () => {
    it('decodes 720p frames, not 1080p', () => {
        const o = scannerOptions(250);
        expect(o.videoConstraints.width.ideal).toBe(1280);
        expect(o.videoConstraints.height.ideal).toBe(720);
    });

    it('keeps the caller\'s scan box and faces the back camera', () => {
        const o = scannerOptions(321);
        expect(o.qrbox).toBe(321);
        expect(o.videoConstraints.facingMode).toBe('environment');
        expect(o.fps).toBe(15);
    });
});
