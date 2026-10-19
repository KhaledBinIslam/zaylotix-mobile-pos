import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { enqueueSale, removeFromQueue, markFailed, markShopMismatch, retryEntry, remapQueuedItem, dropQueuedItem, pendingCount, failedCount, offlineSyncFailureKind } from '@/support/offlineQueue';
import { kvGet, kvSet, KEYS } from '@/support/offlineDb';
import { shouldAutoSync } from '@/support/syncModeDecision';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';
import { useToast } from '@/composables/useToast';
import { useI18n } from '@/composables/useI18n';

// "শেষ কখন data update হয়েছে" — a shop owner running for a couple of hours
// offline needs to know how stale the prices/stock they're looking at are,
// not just a bare "no internet" banner. Persisted (not just an in-memory
// ref) so it survives the tab being closed and reopened while still
// offline — the whole point of this timestamp is to answer "as of when"
// across exactly that gap.
const lastOnlineAt = ref(null);
let lastOnlineLoaded = false;
async function touchLastOnlineAt() {
    lastOnlineAt.value = Date.now();
    await kvSet(KEYS.LAST_ONLINE_AT, lastOnlineAt.value);
}

// Module-level (not inside the exported function) so every component that
// calls useOfflineSync() shares the exact same reactive queue/online state
// — Inertia navigates between pages without a full reload, so a fresh
// per-component ref would forget "3 sales pending" the moment the cashier
// left the POS page for Stock and came back.
const queue = ref([]);
const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const syncing = ref(false);
// Module-level so any page (not just AppLayout.vue, which owns the actual
// <Sheet>) can open the pending-sales sheet — e.g. a "pending sales"
// button on Pos/Index.vue itself (docs/FIX_PLAN.md Phase 12).
const pendingSheetOpen = ref(false);
let loaded = false;
let listenersBound = false;

async function persist() {
    await kvSet(KEYS.SALE_QUEUE, JSON.parse(JSON.stringify(queue.value)));
}

async function ensureLoaded() {
    if (loaded) return;
    loaded = true;
    queue.value = await kvGet(KEYS.SALE_QUEUE, []);
}

/**
 * Replays every still-pending queued sale against the real checkout
 * endpoint — the exact same one a normal online sale hits, so server-side
 * validation (stock, prescription, etc.) re-runs for real at sync time
 * instead of trusting whatever was true when the cashier tapped checkout
 * offline. A sale that no longer validates (e.g. someone else already sold
 * the last unit while this device was offline) is marked 'failed' with the
 * server's own message and kept in the queue for the cashier to review —
 * never silently dropped, never silently double-sold.
 */
async function trySync() {
    if (syncing.value || !isOnline.value) return;
    await ensureLoaded();
    const pending = queue.value.filter((s) => s.status === 'pending');
    if (!pending.length) return;

    syncing.value = true;
    try {
        for (const entry of pending) {
            // Real bug (see docs/FIX_PLAN.md): a sale queued under one
            // shop (e.g. during an admin's impersonation session) could
            // sit here past that session ending, then get replayed under
            // a DIFFERENT shop's session — every product_id in it
            // genuinely doesn't exist under the new tenant scope, giving
            // a misleading generic "product not found" instead of the
            // real problem. Caught HERE, client-side, before even
            // attempting the request — an older queued entry with no
            // shop_id (from before this fix) just skips the check and
            // syncs exactly as it always did.
            const currentShopId = usePage().props.shop?.id;
            if (entry.payload.shop_id && currentShopId && entry.payload.shop_id !== currentShopId) {
                queue.value = markShopMismatch(
                    queue.value,
                    entry.id,
                    `এই বিলটি "${entry.payload.shop_name || 'অজানা দোকান'}"-এর জন্য সারিবদ্ধ হয়েছিল — এই দোকানে সিঙ্ক করা যাবে না।`,
                    entry.payload.shop_name,
                );
                await persist();
                continue; // don't block the rest of the queue behind this one
            }

            try {
                // fetchWithSessionRetry: a sale can sit queued for hours
                // (the entire point of this queue) before the device comes
                // back online to replay it — exactly the scenario where the
                // X-CSRF-TOKEN below, read from a meta tag that may not have
                // been refreshed in all that time, goes stale. See
                // docs/SESSION_FIX.md: this is believed to be the real
                // cause of a reported "sale(s) failed to sync" with nothing
                // in the server log — a 401/419 used to fall into the same
                // generic 4xx bucket as a real business rejection (stock
                // gone, bad product) below, marked 'permanent' and shown as
                // a scary, misleading "needs review" instead of what it
                // actually was: a transient auth hiccup that fixes itself.
                const res = await fetchWithSessionRetry(route('app.pos.checkout'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    // offline_replay: true marks this specifically as a
                    // replay of a previously-queued sale (not a fresh live
                    // checkout) — lets the server allow resolving a
                    // product that's since been soft-deleted, since this
                    // sale genuinely happened before that deletion.
                    body: JSON.stringify({ ...entry.payload, offline_replay: true }),
                });
                const data = await res.json().catch(() => ({}));

                if (res.ok) {
                    queue.value = removeFromQueue(queue.value, entry.id);
                    // the cashier who rang this sale up offline has long
                    // since moved on to the next customer — this is the
                    // only signal they ever get that it actually went
                    // through (see the Phase 1 fix notes: previously silent)
                    const { toast } = useToast();
                    const { t } = useI18n();
                    toast('✅ ' + t('offline.syncedToast', { invoice: data?.sale?.invoice_no || '' }));
                    // A receipt printed/WhatsApp'd before sync shows the
                    // local "OFF-XXXXXXXX" placeholder — this is the only
                    // record of what it became, so a cashier can later look
                    // it up (docs/FIX_PLAN.md Phase 12). Best-effort, capped
                    // so this never grows unbounded.
                    if (entry.payload.client_uuid && data?.sale?.invoice_no) {
                        const map = await kvGet(KEYS.SYNCED_MAP, []);
                        const next = [{
                            localInvoice: 'OFF-' + entry.payload.client_uuid.replace(/-/g, '').slice(0, 8).toUpperCase(),
                            realInvoice: data.sale.invoice_no,
                            saleId: data.sale.id,
                            syncedAt: Date.now(),
                        }, ...map].slice(0, 20);
                        await kvSet(KEYS.SYNCED_MAP, next);
                    }
                } else if (res.status === 409 && data.shop_mismatch) {
                    // defense in depth — the pre-check above should catch
                    // this first, but an entry queued by an older cached
                    // build (before shop_id existed) could still reach
                    // here and get this from the server instead
                    queue.value = markShopMismatch(queue.value, entry.id, data.message, data.queued_for_shop_name);
                } else if (res.status === 422 && Array.isArray(data.problem_items) && data.problem_items.length) {
                    // A real business rejection — resubmitting this exact
                    // payload will fail identically forever (see
                    // retryEntry's own comment), so this is 'permanent',
                    // not 'transient': the resolution sheet, not the plain
                    // retry button, is the actual way out.
                    queue.value = markFailed(queue.value, entry.id, data.message, 'permanent', data.problem_items);
                } else {
                    // offlineSyncFailureKind (see docs/SESSION_FIX.md): a
                    // 401/419 means fetchWithSessionRetry's own one recovery
                    // attempt above already failed, or this really is a
                    // logout — either way it's a session/CSRF hiccup, not a
                    // rejection of the SALE, so it's 'transient' exactly
                    // like a 5xx is, never the permanent/needs-review
                    // bucket an actual business rejection (stock ran out,
                    // unit/variant mismatch, etc.) below still correctly
                    // gets.
                    const kind = offlineSyncFailureKind(res.status);
                    const message = kind === 'transient' && (res.status === 401 || res.status === 419)
                        ? 'সেশনের সমস্যা — ইন্টারনেট/লগইন ঠিক থাকলে এটি নিজে থেকেই সিঙ্ক হয়ে যাবে।'
                        : data.message || Object.values(data.errors || {})[0]?.[0] || 'সিঙ্ক ব্যর্থ হয়েছে। সার্ভারে সাময়িক সমস্যা হলে কিছুক্ষণ পর আবার চেষ্টা করুন।';
                    queue.value = markFailed(queue.value, entry.id, message, kind);
                }
            } catch {
                // still offline (or the network dropped mid-sync) — stop
                // here, leave the rest pending for the next 'online' event
                break;
            }
            await persist();
        }
    } finally {
        syncing.value = false;
    }
}

// null when not counting down; otherwise seconds remaining — drives a
// dismissible "N sales syncing in Ns — stop" banner (docs/FIX_PLAN.md
// Phase 12) so a cashier gets one last look before an auto-sync (the
// default behavior, unchanged) actually sends anything.
const autoSyncCountdown = ref(null);
let autoSyncTimer = null;

function stopAutoSyncCountdown() {
    if (autoSyncTimer) clearInterval(autoSyncTimer);
    autoSyncTimer = null;
    autoSyncCountdown.value = null;
}

/**
 * The actual entry point coming back online (or loading the page while
 * already online) now goes through — NOT straight into trySync() anymore.
 * 'review' mode (shop setting, owner opt-in, see Shop::offline_sync_mode)
 * skips auto-syncing entirely; pendingCount's existing banner already
 * tells the owner/cashier sales are waiting, and they open the pending-
 * sales sheet and tap "sync all" themselves. 'auto' (default — identical
 * to this app's behavior before this fix) still ends up calling trySync(),
 * just after a 30s countdown that can be stopped.
 */
async function maybeAutoSync() {
    await ensureLoaded();
    if (!queue.value.some((s) => s.status === 'pending')) return;
    if (!shouldAutoSync(usePage().props.shop?.offline_sync_mode)) return;
    if (autoSyncCountdown.value !== null) return; // already counting down

    autoSyncCountdown.value = 30;
    autoSyncTimer = setInterval(() => {
        if (autoSyncCountdown.value === null) return;
        autoSyncCountdown.value -= 1;
        if (autoSyncCountdown.value <= 0) {
            stopAutoSyncCountdown();
            trySync();
        }
    }, 1000);
}

function bindListeners() {
    if (listenersBound || typeof window === 'undefined') return;
    listenersBound = true;
    window.addEventListener('online', () => {
        isOnline.value = true;
        touchLastOnlineAt();
        maybeAutoSync();
    });
    window.addEventListener('offline', () => {
        isOnline.value = false;
        stopAutoSyncCountdown();
    });
}

export function useOfflineSync() {
    bindListeners();
    if (!lastOnlineLoaded) {
        lastOnlineLoaded = true;
        kvGet(KEYS.LAST_ONLINE_AT, null).then((v) => {
            // don't clobber a timestamp touchLastOnlineAt() may have already
            // set this session (e.g. the 'online' listener firing before
            // this async load resolves)
            if (lastOnlineAt.value === null) lastOnlineAt.value = v;
        });
    }
    ensureLoaded().then(() => {
        if (isOnline.value) {
            touchLastOnlineAt();
            maybeAutoSync();
        }
    });

    async function queueSale(payload, meta = {}) {
        await ensureLoaded();
        queue.value = enqueueSale(queue.value, payload, meta);
        await persist();
        return queue.value[queue.value.length - 1];
    }

    async function retry(id) {
        queue.value = retryEntry(queue.value, id);
        await persist();
        trySync();
    }

    async function discard(id) {
        queue.value = removeFromQueue(queue.value, id);
        await persist();
    }

    // Resolution path for a 'permanent' failure's named problem item (see
    // trySync's problem_items handling) — fixes the stuck line in place
    // and immediately retries the whole bill, rather than discarding
    // anything. Never drops a sale's OTHER lines just because one of them
    // had a bad product id.
    async function resolveItem(id, productId, newProductId, newProductName) {
        queue.value = remapQueuedItem(queue.value, id, productId, newProductId, newProductName);
        await persist();
        trySync();
    }

    async function dropItem(id, productId) {
        queue.value = dropQueuedItem(queue.value, id, productId);
        await persist();
        trySync();
    }

    // The queued sale never reached the server (that's the whole point of
    // the offline queue), so there's no Sale row to void/reverse — this is
    // purely a local removal. Logging the reason server-side (best-effort;
    // a failure here never blocks the actual cancel) is the only trace
    // that this bill existed and was deliberately abandoned, not lost.
    async function cancelWithReason(id, reason) {
        const entry = queue.value.find((s) => s.id === id);
        try {
            await fetch(route('app.pos.discardOffline'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ reason, payload: entry?.payload }),
            });
        } catch {
            // best-effort audit log only — the cancel itself must still
            // succeed even if this request never reaches the server
        }
        queue.value = removeFromQueue(queue.value, id);
        await persist();
    }

    async function getSyncedMap() {
        return kvGet(KEYS.SYNCED_MAP, []);
    }

    return {
        isOnline,
        syncing,
        queue,
        lastOnlineAt,
        autoSyncCountdown,
        pendingSheetOpen,
        pendingCount: computed(() => pendingCount(queue.value)),
        failedCount: computed(() => failedCount(queue.value)),
        queueSale,
        trySync,
        retry,
        discard,
        resolveItem,
        dropItem,
        cancelWithReason,
        cancelAutoSync: stopAutoSyncCountdown,
        getSyncedMap,
    };
}
