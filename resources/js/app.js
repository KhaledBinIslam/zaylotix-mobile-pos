import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { isInApp } from './composables/useInApp';
import { useToast } from './composables/useToast';
import { useI18n } from './composables/useI18n';
import { reportClientError } from './support/reportClientError';
import { navErrorMessage } from './support/navErrorMessage';
import { startSessionKeepAlive } from './support/sessionKeepAlive';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// See app.blade.php for #boot-splash's own markup/comment. Measured from
// here (script start, effectively page-load time) rather than from the
// moment the splash is removed, so a fast load still shows the logo
// briefly instead of it blinking in and out — a sub-150ms flash reads as
// a glitch, not as "the app opened." A slow load just removes it the
// instant Vue is actually ready, no artificial extra wait either way.
const bootStart = performance.now();
function removeBootSplash() {
    const splash = document.getElementById('boot-splash');
    if (!splash) return;
    const MIN_VISIBLE_MS = 450;
    const wait = Math.max(0, MIN_VISIBLE_MS - (performance.now() - bootStart));
    setTimeout(() => {
        splash.classList.add('leaving');
        setTimeout(() => splash.remove(), 400);
    }, wait);
}

// Runs once, as early as possible, so every page component's own
// `isInApp()` call (no args, just reads the cached result) is already
// settled before anything renders — see useInApp.js for the 3-signal
// detection this performs.
isInApp();

// A plain Inertia form submission (router.post/useForm().post, used all over
// this app — e.g. Restaurant Order.vue's "বিল করুন") had no feedback at all
// on a genuine network failure: unlike the raw-fetch POS checkout screens
// (which explicitly catch a connectivity error and queue the sale offline),
// Inertia's own visits just silently went nowhere, leaving a cashier with no
// idea whether anything happened. useToast's refs are module-level (not tied
// to a component's setup context), so this works globally, before any page
// has even mounted. This is purely additive feedback — it doesn't change
// what any individual page's own onError/onSuccess handlers already do.
// Real bug, reported with a screenshot (docs/FIX_PLAN.md): tapping a
// sidebar/bottom-nav link while offline left the cashier stuck on the
// current page with a confusing "কিছু একটা ভুল হয়েছে" toast — generic,
// scary-sounding, and not the actual message this handler was writing.
// Root cause traced through Inertia's own internals: `event` here is
// Inertia's cancelable `inertia:exception` DOM event, and this handler
// never called `event.preventDefault()` — so even though this ran and
// IS the code "catching" the failed navigation, Inertia's router treats
// an unprevented exception event as "not handled" and re-throws it as a
// genuine unhandled promise rejection afterward, which the OTHER, more
// generic safety-net handler below (window 'unhandledrejection') then
// ALSO catches and displays instead — its toast overwrites this one's
// within the same tick, since useToast() holds one shared message ref.
// preventDefault() stops that double-handling, making this the one true
// message the cashier actually sees. A failed Inertia visit never swaps
// the page (Inertia's own Response.handle() is only reached on success),
// so the user staying on their current page needs no extra code here —
// that was already true; only the WRONG, less-actionable message was the
// actual bug.
router.on('exception', (event) => {
    event.preventDefault();
    const { toast } = useToast();
    const { t } = useI18n();
    toast(t(navErrorMessage(navigator.onLine)));
});

// Broader safety net than the Inertia-specific one above — any raw-fetch
// screen (POS/Clothing/Restaurant checkout, addItem, etc.) that throws
// somewhere unexpected, outside what its own try/catch anticipated, used to
// fail as a silent unhandled promise rejection: visible only in devtools,
// completely invisible to a cashier who just sees their tap do nothing.
// This is exactly the failure mode a real bug turned out to be (Restaurant
// Order.vue's postItem() building its request URL outside its own try
// block) — fixed there specifically, but this catches the same CLASS of
// bug anywhere else in the app too, present or future.
window.addEventListener('unhandledrejection', (event) => {
    reportClientError(event.reason, 'unhandledrejection');
    const { toast } = useToast();
    toast('⚠️ কিছু একটা ভুল হয়েছে — আবার চেষ্টা করুন। সমস্যা থাকলে পেজ রিলোড করুন।');
});

// Real gap, reported live with a screenshot (docs/FIX_PLAN.md Phase 12):
// a cashier who went offline while sitting on Home (or any page other
// than Sell itself) tapped "Sell" and got the new, correctly-worded
// "needs internet" message — technically working as designed, but wrong
// in spirit, since Sell is the one screen offline selling specifically
// exists for. The service worker (public/sw.js) only ever caches a page
// as a side effect of someone actually visiting it online first — no
// guarantee Sell was ever the page that happened to be open last. This
// uses Inertia's own real prefetch (router.prefetch — a genuine request
// with the correct X-Inertia headers, so the service worker's fetch
// handler caches it exactly the way a real visit would, not a
// hand-rolled fetch() that would produce a differently-shaped, never-
// matched cache entry) to top up Sell's cached copy on every cold load
// while online, regardless of which page is actually open. Same
// isRestaurant logic as AppLayout.vue's own mobileSellHref, since a
// restaurant shop's "Sell" is a different route entirely.
function prefetchSellPage(initialProps) {
    if (!navigator.onLine || !initialProps) return;
    const isRestaurant = initialProps.shop?.business_type_slug === 'restaurant';
    const href = isRestaurant ? route('app.restaurant.tables.index') : route('app.pos');
    // What Inertia itself does with the response (its own in-memory
    // prefetch cache, governed by cacheFor) doesn't matter here — the
    // point is purely the side effect of a real request flowing through
    // the service worker's fetch handler, which caches it in Cache
    // Storage independently of anything Inertia does afterward.
    router.prefetch(href, { method: 'get' });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
        removeBootSplash();
        prefetchSellPage(props.initialPage?.props);
        // Only for an actually logged-in shop session — the public
        // landing/login pages have no csrf-token route to usefully reach
        // (it sits behind the 'shop' auth middleware) and nobody is
        // realistically idle on them for the 10+ minutes this is for.
        if (props.initialPage?.props?.auth?.user) startSessionKeepAlive();
        return app;
    },
    // Matches --green — this file can't read CSS custom properties, Inertia's
    // progress plugin wants a literal color string, so the hex is repeated
    // here; it's brand-identity, not a token that's ever expected to drift
    // on its own without someone also updating this to match.
    progress: {
        color: '#1FA463',
    },
});

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });

    // Safe update propagation for the one real, still-open gap: /build/*
    // (the JS/CSS bundle) is cache-first FOREVER once fetched (see sw.js),
    // so a phone/APK that had the app open from before a deploy could stay
    // stuck running that old code indefinitely — nothing native (this app's
    // WebView already forces LOAD_NO_CACHE at the native HTTP layer) touches
    // the service worker's OWN separate Cache API storage. A first attempt
    // at fixing this (sw.js v4) reloaded every open tab the INSTANT a new
    // service worker took over, with zero regard for whether anyone was
    // mid-sale — reverted immediately after it visibly disrupted live
    // selling ("app/pos kaj korche na"). This is the safe version: wait for
    // the service worker handoff (controllerchange — fires once a new
    // version has actually taken over), then wait AGAIN for the one moment
    // nobody's looking at this tab (backgrounded — the shop app minimized,
    // or the phone locked) before reloading. By the time it's looked at
    // again, it's already fresh; nothing on screen is ever interrupted.
    let swRefreshPending = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (swRefreshPending) return;
        swRefreshPending = true;
        const reloadWhenHidden = () => {
            if (document.visibilityState !== 'hidden') return;
            document.removeEventListener('visibilitychange', reloadWhenHidden);
            window.location.reload();
        };
        if (document.visibilityState === 'hidden') {
            reloadWhenHidden();
        } else {
            document.addEventListener('visibilitychange', reloadWhenHidden);
        }
    });
}
