const CACHE_NAME = 'panda-cache-v2';

const PRECACHE_URLS = [
  '/public/',
  '/public/index.html',
  '/public/manifest.webmanifest?v=2',
  '/public/registerSW.js',
  '/public/pwa-64x64.png?v=2',
  '/public/pwa-192x192.png?v=2',
  '/public/pwa-512x512.png?v=2',
  '/public/maskable-icon-512x512.png?v=2',
  '/public/apple-touch-icon-180x180.png',
  '/public/favicon.ico',
  '/public/favicon-32x32.png',
  '/public/icons/icon-192x192.png',
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((response) => {
        if (!response || response.status !== 200 || response.type === 'opaque') {
          return response;
        }
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        return response;
      });
    })
  );
});
