// Pure helper for the POS customer-phone watcher's stale-auto-fill guard
// (see Pos/Index.vue's watch(customerPhone, ...)) — extracted so the actual
// bug fix is unit-testable without mounting the whole page component.
//
// Reported bug: typing phone A auto-filled customer A's name; editing the
// phone to a different existing customer B left A's name sitting in the
// field, which then got posted to checkout and silently overwrote B's real
// name server-side (PosController::performCheckout's "update name if it
// differs from what was posted" logic trusted it blindly). A hand-typed
// name must never be touched by this guard — only our own previous
// auto-fill for a now-stale phone should ever be cleared.

/**
 * @param {string} currentName - whatever is currently in the name field
 * @param {string|null} lastAutoFilledName - the exact string this code itself last auto-filled, or null if the field's current value was hand-typed/never auto-filled
 * @returns {boolean} true if currentName is exactly our own stale auto-fill and should be cleared before a new phone lookup runs
 */
export function isStaleAutoFill(currentName, lastAutoFilledName) {
    return lastAutoFilledName !== null && currentName === lastAutoFilledName;
}
