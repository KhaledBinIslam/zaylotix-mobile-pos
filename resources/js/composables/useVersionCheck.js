import { ref, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Phase 40 (docs/CUSTOMIZATION_AUDIT.md কারণ ৩) — "নতুন সংস্করণ এসেছে"
 * detection for a tab/PWA that just sits open and never navigates (so
 * Inertia's own 409 version check never fires for it).
 *
 * Checks the server's current build version (GET app.appVersion — a tiny
 * JSON response, no page payload) against the version this tab loaded
 * with, (a) whenever the app comes back to the foreground — the exact
 * moment an owner unlocks their phone and would otherwise stare at stale
 * UI — and (b) on a slow interval while it stays open. On mismatch it
 * only flips `newVersionAvailable`; the banner's refresh button is the
 * user's own choice. NEVER reloads on its own — sw.js v4 was reverted for
 * exactly that (it wiped mid-sale state on live counters).
 */
export function useVersionCheck() {
    const page = usePage();
    const newVersionAvailable = ref(false);
    const dismissed = ref(false);

    // captured once at mount — props.assetVersion updates on later Inertia
    // navigations, but those already self-heal via Inertia's 409; this
    // composable only cares about the version the running BUNDLE came from
    let loadedVersion = null;
    let lastCheckAt = 0;
    let timer = null;

    const MIN_GAP_MS = 2 * 60 * 1000; // never hit the server more than once per 2 min
    const INTERVAL_MS = 15 * 60 * 1000;

    async function check() {
        if (newVersionAvailable.value || !loadedVersion) return;
        const now = Date.now();
        if (now - lastCheckAt < MIN_GAP_MS) return;
        lastCheckAt = now;
        try {
            const res = await fetch(route('app.appVersion'), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!res.ok) return; // expired session/offline — silently skip, never nag
            const data = await res.json();
            if (data.v && data.v !== loadedVersion) newVersionAvailable.value = true;
        } catch {
            // offline — the next foreground/interval check will try again
        }
    }

    function onVisible() {
        if (document.visibilityState === 'visible') check();
    }

    onMounted(() => {
        loadedVersion = page.props.assetVersion || null;
        document.addEventListener('visibilitychange', onVisible);
        timer = setInterval(check, INTERVAL_MS);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        clearInterval(timer);
    });

    return { newVersionAvailable, dismissed };
}
