<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="theme-color" content="#1FA463">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script>
            // Applies an explicit light/dark CHOICE (see useTheme.js) before
            // anything paints, so reloading on a saved "dark" never flashes
            // light first. Someone who never touched the toggle gets no
            // data-theme attribute at all — app.css's prefers-color-scheme
            // media query handles that case with zero JS needed.
            try {
                var t = localStorage.getItem('zaylotix_theme');
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        </script>
        <link rel="manifest" href="/manifest.json">

        <title inertia>{{ config('app.name', 'Zaylotix POS') }}</title>
        <link rel="icon" href="/favicon-32.png?v=2" sizes="32x32" type="image/png">
        <link rel="icon" href="/favicon-16.png?v=2" sizes="16x16" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Baloo+Da+2:wght@600;700;800&display=swap" rel="stylesheet">
        <!-- Space Grotesk (Latin) + Baloo Da 2 (Bengali) — a distinct display
             pairing used ONLY for the public landing page's headlines (see
             --font-display in app.css), never the app's own screens. Browsers
             fall back per-glyph within a font-family list, so Latin text in a
             headline renders in Space Grotesk and Bengali text in the same
             headline automatically renders in Baloo Da 2 — no lang-detection
             JS needed. -->

        <!-- Scripts -->
        @routes
        {{--
            Deliberately only app.js here, not the page component (the
            Breeze/Jetstream Inertia starter's usual
            @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
            pattern). Passing the page component made Laravel's Vite
            helper walk ITS import graph too — which lists app.js as a
            dependency (resources/js/Pages/App/Pos/Index.vue's manifest
            entry has "imports": ["resources/js/app.js", ...]) — emitting
            a <link rel=preload as=style> for app.js's own CSS on top of
            the normal <link rel=stylesheet> already emitted for app.js
            as its own entry. Same URL, two tags, and the preload one
            isn't "consumed" the way the browser's heuristic expects —
            exactly Chrome's "was preloaded... but not used within a few
            seconds" warning. Dropping the page-component entry removes
            that redundant walk; it isn't needed for the page's own CSS
            either way, since Inertia's resolve() (app.js's
            import.meta.glob('./Pages/**/*.vue')) dynamically imports
            every page component at runtime regardless — the exact same
            mechanism every SPA navigation after this first load already
            relies on, since no later page was ever listed here.
        --}}
        @vite(['resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        {{-- Real boot splash, not a Vue component — present in the raw HTML
             before any JS runs, so there's zero blank-white flash on a cold
             load (PWA cold start, hard refresh, first visit). app.css's
             stylesheet link above is render-blocking, so --bg/--greenSoft
             etc. are already resolved by the time this paints. Removed by
             app.js right after Vue mounts — never re-shown on an Inertia
             client-side navigation, since those don't reload this file. --}}
        <div id="boot-splash">
            <img src="/icons/icon-192.png?v=2" width="72" height="72" alt="Zaylotix">
        </div>
        @inertia
    </body>
</html>
