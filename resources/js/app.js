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
router.on('exception', (event) => {
    // TEMPORARY diagnostic — see Order.vue's postItem() for why: this event
    // firing right after a raw-fetch success toast can silently clobber it
    // (useToast is a single message slot), which would look exactly like
    // "add says it worked but nothing else on screen ever changes" on
    // whatever's triggering this. alert() so it can't be missed/overwritten
    // the way a toast can, purely to see what event.detail.exception is.
    try {
        alert('INERTIA EXCEPTION: ' + (event?.detail?.exception?.message || event?.detail?.exception || 'unknown'));
    } catch (e) { /* ignore */ }
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
    // TEMPORARY diagnostic — see the router.on('exception') handler above
    // for why alert() instead of just the toast right now.
    try {
        alert('UNHANDLED REJECTION: ' + (event.reason?.message || event.reason));
    } catch (e) { /* ignore */ }
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
}
