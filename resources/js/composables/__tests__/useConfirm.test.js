import { describe, expect, it } from 'vitest';
import { confirmAsync, confirmState } from '../useConfirm.js';

/**
 * Drop-in async replacement for window.confirm()/confirm() — see
 * ConfirmDialog.vue's docblock for why: a native confirm() is known to
 * get silently auto-dismissed (resolves false, nothing visible) inside
 * some Android WebView/PWA contexts, exactly how this app runs on a
 * shop's own device — real bug, 0 sales ever going through on a live
 * clothing demo shop.
 */
describe('confirmAsync', () => {
    it('opens confirmState with the given message', () => {
        confirmAsync('Delete this?');
        expect(confirmState.value.open).toBe(true);
        expect(confirmState.value.message).toBe('Delete this?');
        confirmState.value.resolve(false); // clean up for the next test
    });

    it('resolves true when the stored resolver is called with true (the "Yes" tap)', async () => {
        const promise = confirmAsync('Proceed?');
        confirmState.value.resolve(true);
        await expect(promise).resolves.toBe(true);
    });

    it('resolves false when the stored resolver is called with false (Cancel, or the backdrop tap)', async () => {
        const promise = confirmAsync('Proceed?');
        confirmState.value.resolve(false);
        await expect(promise).resolves.toBe(false);
    });

    it('a second confirmAsync() call replaces the pending state for the next dialog', () => {
        confirmAsync('First?');
        confirmState.value.resolve(false);
        confirmAsync('Second?');
        expect(confirmState.value.message).toBe('Second?');
        confirmState.value.resolve(false);
    });
});
