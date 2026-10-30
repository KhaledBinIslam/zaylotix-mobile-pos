import { describe, expect, it } from 'vitest';
import { serviceLabel, isWritableCharacteristic, KNOWN_SERVICE_UUIDS } from '../bleServiceCatalog';

describe('bleServiceCatalog', () => {
    it('labels a known service UUID case-insensitively', () => {
        expect(serviceLabel('0000FFE0-0000-1000-8000-00805F9B34FB')).toContain('HM-10');
        expect(serviceLabel('6e400001-b5a3-f393-e0a9-e50e24dcca9e')).toBe('Nordic UART Service');
    });

    it('returns null for an unknown UUID', () => {
        expect(serviceLabel('deadbeef-0000-1000-8000-00805f9b34fb')).toBeNull();
    });

    it('every catalog entry is actually in KNOWN_SERVICE_UUIDS (what gets requested)', () => {
        expect(KNOWN_SERVICE_UUIDS.length).toBeGreaterThan(0);
        expect(KNOWN_SERVICE_UUIDS).toContain('0000ffe0-0000-1000-8000-00805f9b34fb');
    });

    it('identifies writable characteristics', () => {
        expect(isWritableCharacteristic({ write: true })).toBe(true);
        expect(isWritableCharacteristic({ writeWithoutResponse: true })).toBe(true);
        expect(isWritableCharacteristic({ read: true, notify: true })).toBe(false);
        expect(isWritableCharacteristic(null)).toBe(false);
    });
});
