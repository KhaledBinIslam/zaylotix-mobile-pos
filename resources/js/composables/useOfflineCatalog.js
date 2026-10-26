import { ref, computed } from 'vue';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { putProducts, searchProductsOffline, findProductByBarcode } from '@/support/offlineDb';
import { fetchWithSessionRetry } from '@/support/fetchWithSessionRetry';

// Scale follow-up (docs/SCALING_GUIDE.md item #4, docs/FIX_PLAN.md's design
// note) — only relevant once PosController::index() stops sending the whole
// catalog (catalogMode === 'search'). Module-level shared state, same
// reasoning as useOfflineSync.js: Inertia navigates without a full reload,
// so a fresh per-component ref would lose sync progress if the cashier left
// POS for another page and came back within the same tab.
const syncProgress = ref({ done: 0, total: null, complete: false });
let syncStarted = false;
let searchDebounceTimer = null;

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    return token ? { 'X-CSRF-TOKEN': token } : {};
}

async function fetchChunk(afterId) {
    const url = afterId ? `${route('app.pos.catalogSync')}?after_id=${afterId}` : route('app.pos.catalogSync');
    const res = await fetchWithSessionRetry(url, { headers: { Accept: 'application/json', ...csrfHeaders() } });
    if (!res.ok) throw new Error('catalog sync chunk failed');

    return res.json();
}

/**
 * Loops catalog-sync chunks until the whole catalog is cached locally, so
 * offline search/barcode-scan keeps working once this finishes — see
 * offlineDb.js's PRODUCTS store. A small pause between chunks is the
 * same "don't hammer shared hosting" instinct behind this app's own
 * checkout rate limit; this is a background task, not something a cashier
 * is ever waiting on to sell.
 */
async function runBackgroundSync(productCount) {
    syncProgress.value = { done: 0, total: productCount, complete: false };
    let afterId = null;

    try {
        do {
            const { products, next_after_id: nextAfterId } = await fetchChunk(afterId);
            await putProducts(products);
            syncProgress.value = { ...syncProgress.value, done: syncProgress.value.done + products.length };
            afterId = nextAfterId;
            if (afterId) await new Promise((resolve) => setTimeout(resolve, 200));
        } while (afterId);

        syncProgress.value = { ...syncProgress.value, complete: true };
    } catch {
        // best-effort — a failed sync just leaves the offline fallback
        // partially populated; nothing here is ever on the critical path
        // for an online sale, since search()/resolveBarcode() below only
        // ever fall back to the local cache when the network itself fails
    }
}

export function useOfflineCatalog() {
    const { isOnline } = useOfflineSync();

    function startBackgroundSync(productCount) {
        if (syncStarted) return;
        syncStarted = true;
        runBackgroundSync(productCount);
    }

    /**
     * Debounced (300ms, matching this app's one existing precedent — the
     * pharmacy medicine-catalog lookup) server search, falling back to the
     * local cache when offline or the request itself fails. `categoryId`
     * follows Pos/Index.vue's own 'all' sentinel for "no filter".
     */
    function search(query, categoryId) {
        return new Promise((resolve) => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(async () => {
                if (isOnline.value) {
                    try {
                        const params = new URLSearchParams();
                        if (query) params.set('q', query);
                        if (categoryId && categoryId !== 'all') params.set('category_id', categoryId);
                        const res = await fetchWithSessionRetry(`${route('app.pos.search')}?${params.toString()}`, {
                            headers: { Accept: 'application/json' },
                        });
                        if (res.ok) {
                            resolve((await res.json()).products);

                            return;
                        }
                    } catch {
                        // fall through to the offline cache below
                    }
                }
                resolve(await searchProductsOffline(query, categoryId));
            }, 300);
        });
    }

    /**
     * Mirrors Pos/Index.vue's existing barcode fallback order (in-memory
     * first, handled by the caller before this is even reached; then the
     * server) — this only adds one more step after the server attempt
     * fails or there's no network at all: the local offline cache.
     */
    async function resolveBarcode(code) {
        if (isOnline.value) {
            try {
                const res = await fetchWithSessionRetry(route('app.pos.barcode', code), { headers: { Accept: 'application/json' } });
                if (res.ok) return res.json();
            } catch {
                // fall through to the offline cache below
            }
        }

        const found = await findProductByBarcode(code);

        return found ? { found: true, product: found.product, variant_id: found.variantId } : { found: false };
    }

    return {
        syncProgress: computed(() => syncProgress.value),
        startBackgroundSync,
        search,
        resolveBarcode,
    };
}
