/**
 * When may a tab that has detected a NEW build reload itself?
 *
 * Until now a new build only raised a banner nobody taps, so a phone kept
 * running yesterday's JS — and yesterday's bugs (the scanners that never
 * read a sticker) — for days after a fix was live. The one earlier attempt
 * at reloading on its own (sw.js v4) wiped a cashier's screen mid-sale and
 * was reverted, so this decides ONLY at a provably safe moment: the person
 * has not touched the screen for a while and the device is online (a
 * reload offline would land on the cached shell, not the new build).
 * A cart or form someone is actually filling in always has input within
 * the window, so it is never interrupted; a POS cart is also persisted for
 * recovery (saveCartForRecovery) as a second net.
 */
export const IDLE_BEFORE_RELOAD_MS = 90 * 1000;

/**
 * @param {{ now: number, lastInputAt: number, online: boolean, idleMs?: number }} s
 * @returns {boolean}
 */
export function shouldAutoReload({ now, lastInputAt, online, idleMs = IDLE_BEFORE_RELOAD_MS }) {
    if (!online) return false;
    return now - lastInputAt >= idleMs;
}
