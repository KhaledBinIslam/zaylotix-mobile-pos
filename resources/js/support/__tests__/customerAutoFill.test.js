import { describe, expect, it } from 'vitest';
import { isStaleAutoFill } from '../customerAutoFill.js';

describe('isStaleAutoFill', () => {
    it('is false when nothing has ever been auto-filled', () => {
        expect(isStaleAutoFill('', null)).toBe(false);
        expect(isStaleAutoFill('Karim', null)).toBe(false);
    });

    it('is true when the current name is exactly our own previous auto-fill', () => {
        expect(isStaleAutoFill('Alice', 'Alice')).toBe(true);
    });

    it('is false for a hand-typed name that never matched any auto-fill', () => {
        expect(isStaleAutoFill('Karim Hossain', null)).toBe(false);
    });

    it('is false once the cashier has edited the auto-filled name', () => {
        // auto-filled "Alice", cashier corrected it to "Alice M." — must never be wiped
        expect(isStaleAutoFill('Alice M.', 'Alice')).toBe(false);
    });

    it('is false after the field has already been cleared', () => {
        expect(isStaleAutoFill('', 'Alice')).toBe(false);
    });
});
