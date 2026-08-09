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
