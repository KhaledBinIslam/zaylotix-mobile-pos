import { ref, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { shouldAutoReload } from '@/support/autoUpdate';

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
 * user's own choice — AND, once nobody has touched the screen for a while,
 * it reloads itself (support/autoUpdate.js decides when that is safe), so a
 * phone never keeps running yesterday's bugs just because the banner went
 * untapped. Never while the screen is in use: sw.js v4 was reverted for
 * reloading mid-sale on live counters.
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
    let idleTimer = null;
    let lastInputAt = Date.now();
    const touch = () => { lastInputAt = Date.now(); };
    const INPUT_EVENTS = ['pointerdown', 'keydown', 'touchstart', 'input', 'wheel'];

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
            if (data.v && data.v !== loadedVersion) {
                newVersionAvailable.value = true;
                startIdleReload();
            }
        } catch {
            // offline — the next foreground/interval check will try again
        }
    }

    // checked every 15 s only AFTER a new build is known — no cost until then
    function startIdleReload() {
        if (idleTimer) return;
        idleTimer = setInterval(() => {
            if (shouldAutoReload({ now: Date.now(), lastInputAt, online: navigator.onLine })) {
                window.location.reload();
            }
        }, 15 * 1000);
    }
    function onVisible() {
        if (document.visibilityState === 'visible') check();
    }

    onMounted(() => {
        loadedVersion = page.props.assetVersion || null;
        document.addEventListener('visibilitychange', onVisible);
        INPUT_EVENTS.forEach((e) => document.addEventListener(e, touch, { passive: true, capture: true }));
        timer = setInterval(check, INTERVAL_MS);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        INPUT_EVENTS.forEach((e) => document.removeEventListener(e, touch, { capture: true }));
        clearInterval(timer);
        clearInterval(idleTimer);
    });

    return { newVersionAvailable, dismissed };
}
