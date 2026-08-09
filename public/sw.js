// App-shell + offline-page service worker for the Zaylotix PWA.
//
// v1 was network-first for /app/* with NO write-through caching for those
// responses at all — meaning the "fall back to cache" branch was dead code:
// nothing had ever put a page response into the cache, so a genuinely
// offline reopen of the app (device restart, closed tab, browser crash)
// just failed outright. That's exactly the gap Khaled asked to close: a
// shop needs to keep running for hours offline, not just survive a
// mid-session network blip on an already-open tab.
//
// v2 strategy:
//   - /build/* (JS/CSS bundles): cache-first, unchanged from v1.
//   - Everything else same-origin GET (page navigations AND Inertia's own
//     XHR partial/full page fetches to /app/*): network-first, but every
//     successful response is now written through to cache, so the LAST
//     good copy of whatever page/data was last loaded online is what gets
//     served back when a later request for that same URL fails. Inertia
//     embeds the page's data directly in that response (server-rendered
//     data-page JSON for a hard load, or the JSON body for an SPA visit),
//     so serving the cached copy also restores the last-known
//     products/stock/tables/etc. — no separate offline data store needed.
//   - Real checkout/mutation POSTs are never cached (method !== 'GET' is
//     skipped entirely, same as v1) — those go through the app's own
//     explicit offline queues (see useOfflineSync.js /
//     useOfflineActionQueue.js), never a service-worker cache.

const CACHE = 'zaylotix-shell-v2';
const SHELL_ASSETS = ['/manifest.json'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // never cache third-party requests (fonts CDN, gateway redirects, etc.)

  const isBuildAsset = url.pathname.startsWith('/build/');

  if (isBuildAsset) {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((res) => {
        const clone = res.clone();
        caches.open(CACHE).then((cache) => cache.put(request, clone));
        return res;
      }))
    );
    return;
  }

  // network-first, write-through cache, fall back to the last good copy —
  // covers both a hard page navigation and Inertia's own XHR page-visit
  // fetches (they carry different Accept/X-Inertia headers, which the
  // Cache API keys on via the response's own Vary header, so a cached HTML
  // page and a cached Inertia JSON response for the same URL don't collide)
  event.respondWith(
    fetch(request)
      .then((res) => {
        // only cache a genuinely good response — never cache a 4xx/5xx
        // (an expired-session redirect, a validation error page, etc.), or
        // this offline fallback could start serving an error page forever
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then((cache) => cache.put(request, clone));
        }
        return res;
      })
      .catch(() => caches.match(request))
  );
});
