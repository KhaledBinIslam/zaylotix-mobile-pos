import { describe, expect, it } from 'vitest';
import { formatSize } from '../sizeDisplay';

describe('formatSize', () => {
    it('turns a stored half size into the ½ the cap trade writes', () => {
        expect(formatSize('20.5')).toBe('20½');
        expect(formatSize('22.5')).toBe('22½');
    });

    it('leaves every other size exactly as stored', () => {
        expect(formatSize('42')).toBe('42');
        expect(formatSize('XL')).toBe('XL');
        expect(formatSize('20.25')).toBe('20.25'); // only .5 is a trade half
        expect(formatSize('20.5 inch')).toBe('20.5 inch'); // not a bare half size
        expect(formatSize('')).toBe('');
        expect(formatSize(null)).toBe('');
        expect(formatSize(21.5)).toBe('21½'); // numbers welcome too
    });
});
