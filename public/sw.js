// Minimal app-shell service worker for the Byapari PWA.
// Strategy: network-first for navigation/API (POS data must stay fresh —
// this app is server-authoritative, not offline-first), cache-first for
// static build assets so repeat loads are fast and the icon/manifest work
// without a network round-trip.

const CACHE = 'byapari-shell-v1';
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

  // everything else (pages, /app/*, /api/*): network-first, no offline cache
  // of business data — a stale POS screen is worse than a loading spinner.
  event.respondWith(
    fetch(request).catch(() => caches.match(request))
  );
});
