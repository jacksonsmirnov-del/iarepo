// ================================================================
// sw.js — iarepo service worker (minimal, safe)
//
// - Precaches the app shell + icons for offline/installability.
// - Navigations: network-first, fall back to cached "/" when offline.
// - Same-origin static assets: cache-first.
// - Never caches /api/ (always fresh data) or non-GET requests.
// ================================================================

const CACHE = 'iarepo-v1';
const SHELL = [
  '/',
  '/favicon.svg',
  '/assets/img/logo.svg',
  '/assets/img/logo-icon.svg',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;      // let cross-origin pass through
  if (url.pathname.startsWith('/api/')) return;          // never cache the API

  // Navigations → network-first, fall back to cached home when offline.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(() => caches.match(req).then((r) => r || caches.match('/')))
    );
    return;
  }

  // Static same-origin assets → cache-first, then network (and cache it).
  e.respondWith(
    caches.match(req).then((cached) =>
      cached ||
      fetch(req).then((res) => {
        if (res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(() => cached)
    )
  );
});
