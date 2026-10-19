/**
 * Pure logic behind the "পরবর্তী জনকে পাঠান" (send to next) queue —
 * see docs/WHATSAPP_BULK_PLAN.md. The actual window.open() call always
 * happens directly inside the component's own click handler (never from
 * here, never from a setTimeout), since that's the whole fix: a
 * window.open() not triggered synchronously by a real tap loses its
 * "user gesture" status and gets silently popup-blocked — which is
 * exactly what made the old setTimeout-staggered loop in
 * CustomerList/Index.vue only ever open the first chat.
 */

/**
 * Same '88' + digits.replace(/^88/, '') convention used everywhere else in
 * this app a phone number becomes a wa.me link (Home.vue, Customers/Index.vue,
 * Sales/Show.vue, receiptMessage.js, etc.) — phone numbers are stored with
 * their local leading 0 (e.g. "01712345678"), so prepending '88' alone
 * already produces the correct "880…" country code; the trailing
 * replace(/^88/, '') just avoids doubling it up if a number happens to
 * already be stored with the country code included. Returns null for
 * anything that doesn't end up looking like a real phone number.
 */
export function normalizePhone(raw) {
    const digits = (raw || '').replace(/\D/g, '');
    if (!digits) return null;
    const num = '88' + digits.replace(/^88/, '');
    if (num.length < 12 || num.length > 14) return null;

    return num;
}

/**
 * Builds the dedup'd, validated send list from the full selected-customer
 * set: drops anyone who opted out of marketing (no_marketing), anyone
 * without a phone number that normalizes to something real, and collapses
 * two customer records that share one phone number into a single target.
 */
export function buildQueueTargets(customers) {
    const seen = new Set();
    const targets = [];
    const skippedInvalid = [];

    for (const c of customers) {
        if (c.no_marketing) continue;
        const phone = normalizePhone(c.phone);
        if (!phone) {
            skippedInvalid.push(c);
            continue;
        }
        if (seen.has(phone)) continue;
        seen.add(phone);
        targets.push({ id: c.id, name: c.name, due: Number(c.due) || 0, phone });
    }

    return { targets, skippedInvalid };
}

export function renderTemplate(template, target, shopName) {
    return (template || '')
        .replace(/\{name\}/g, target.name || '')
        .replace(/\{due\}/g, String(Math.round(target.due || 0)))
        .replace(/\{shop\}/g, shopName || '');
}

export function waLink(phone, text) {
    return 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);
}

function firstPendingIndex(items, from) {
    for (let i = from; i < items.length; i++) {
        if (items[i].status === 'pending') return i;
    }

    return items.length;
}

export function initQueueState(targets) {
    return stateFromItems(targets.map((t) => ({ ...t, status: 'pending' })));
}

/** Rebuilds a queue state from a previously-saved items array (resuming a batch that was stopped partway through) — just recomputes the cursor, the items' own statuses are trusted as-is. */
export function stateFromItems(items) {
    return { items, cursor: firstPendingIndex(items, 0) };
}

export function currentTarget(state) {
    return state.items[state.cursor] ?? null;
}

export function markCurrent(state, status) {
    if (state.cursor >= state.items.length) return state;
    const items = state.items.slice();
    items[state.cursor] = { ...items[state.cursor], status };

    return { items, cursor: firstPendingIndex(items, state.cursor + 1) };
}

export function sentCount(state) {
    return state.items.filter((i) => i.status === 'sent').length;
}

export function skippedCount(state) {
    return state.items.filter((i) => i.status === 'skipped').length;
}

export function isQueueDone(state) {
    return state.cursor >= state.items.length;
}

/** Every 30th send, a non-blocking reminder to pause a moment — WhatsApp itself can temporarily block a number that fires off too many chats too fast. */
export function shouldShowPauseReminder(sentSoFar) {
    return sentSoFar > 0 && sentSoFar % 30 === 0;
}

/** True when a resumed batch's target set (ids) still matches the current selection — otherwise the saved progress belongs to a different, now-stale selection and should be discarded instead of resumed. */
export function sameTargetSet(savedItems, freshTargets) {
    if (!Array.isArray(savedItems) || savedItems.length !== freshTargets.length) return false;
    const savedIds = new Set(savedItems.map((i) => i.id));

    return freshTargets.every((t) => savedIds.has(t.id));
}
