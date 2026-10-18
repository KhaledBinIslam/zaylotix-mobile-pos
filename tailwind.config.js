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
                'brand-purple': 'var(--purple)',
                'brand-purple-soft': 'var(--purpleSoft)',
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
