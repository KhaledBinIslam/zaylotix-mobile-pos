import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { useToast } from './composables/useToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// A plain Inertia form submission (router.post/useForm().post, used all over
// this app — e.g. Restaurant Order.vue's "বিল করুন") had no feedback at all
// on a genuine network failure: unlike the raw-fetch POS checkout screens
// (which explicitly catch a connectivity error and queue the sale offline),
// Inertia's own visits just silently went nowhere, leaving a cashier with no
// idea whether anything happened. useToast's refs are module-level (not tied
// to a component's setup context), so this works globally, before any page
// has even mounted. This is purely additive feedback — it doesn't change
// what any individual page's own onError/onSuccess handlers already do.
router.on('exception', () => {
    const { toast } = useToast();
    toast('📴 নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন');
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
    console.error('Unhandled promise rejection:', event.reason);
    const { toast } = useToast();
    toast('⚠️ কিছু একটা ভুল হয়েছে — আবার চেষ্টা করুন। সমস্যা থাকলে পেজ রিলোড করুন।');
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
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
