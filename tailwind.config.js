import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Phase 9 (docs/FIX_PLAN.md) — bridges Admin/*'s Tailwind utility
            // classes onto the same CSS custom properties (resources/css/app.css
            // :root) the shop-facing app already uses, instead of Admin reaching
            // for Tailwind's own default emerald/rose/gray palette. Values are
            // read live from the CSS vars, so a future token edit reaches both.
            colors: {
                // DEFAULT reads live from the CSS var (so the dark-mode
                // override in app.css repaints it); the numbered shades
                // are static hex tuned to sit around that same DEFAULT —
                // these replace Admin/*'s old reach for Tailwind's own
                // stock violet-50..900 ramp (a DIFFERENT, unrelated
                // palette that happened to look similar by coincidence).
                'brand-purple': {
                    DEFAULT: 'var(--admin-primary)',
                    50: '#F1EDFF', 100: '#E3DAFF', 200: '#C7B5FF', 300: '#A98EFF',
                    400: '#8B67FF', 500: '#6D4AFF', 600: '#5F3DEE', 700: '#5636D9',
                    800: '#452AB0', 900: '#362086',
                },
                'brand-purple2': 'var(--admin-primary2)',
                'brand-purple-soft': 'var(--admin-primary-soft)',
                'brand-green': 'var(--green)',
                'brand-green-soft': 'var(--greenSoft)',
                'brand-gold': 'var(--gold)',
                'brand-rose': 'var(--rose)',
                'brand-rose-soft': 'var(--roseSoft)',
                'brand-ink': 'var(--ink)',
                'brand-mut': 'var(--mut)',
                'brand-line': 'var(--line)',
                'brand-panel': 'var(--panel)',
                'brand-bg': 'var(--bg)',
            },
        },
    },

    plugins: [forms],
};
