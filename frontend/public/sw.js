/* Cache public assets only. Account pages, API responses and Inertia props stay on the network. */
const CACHE = 'flc-vue3-v1';
const PUBLIC_FILES = [
  '/manifest.json',
  '/offline.html',
  '/images/android-chrome-192x192.png',
  '/images/android-chrome-512x512.png',
  '/images/android-chrome-maskable-512x512.png',
  '/images/apple-touch-icon.png',
];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PUBLIC_FILES)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((names) => Promise.all(names.filter((name) => name.startsWith('flc-') && name !== CACHE).map((name) => caches.delete(name)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => (await caches.match('/offline.html')) || new Response('Offline', {status: 503})));
    return;
  }
  if (!url.pathname.startsWith('/assets/') && !PUBLIC_FILES.includes(url.pathname)) return;
  event.respondWith(caches.match(request).then(async (cached) => {
    if (cached) return cached;
    const response = await fetch(request);
    if (response.ok) { const cache = await caches.open(CACHE); await cache.put(request, response.clone()); }
    return response;
  }));
});
