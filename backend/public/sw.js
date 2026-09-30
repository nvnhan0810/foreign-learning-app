/* FLC PWA service worker — cache static shell; network for app/API. */
const CACHE_VERSION = 'flc-v2';
const PRECACHE = `${CACHE_VERSION}-precache`;
const RUNTIME = `${CACHE_VERSION}-runtime`;

const PRECACHE_URLS = [
  '/manifest.json',
  '/images/android-chrome-192x192.png',
  '/images/android-chrome-512x512.png',
  '/images/android-chrome-maskable-512x512.png',
  '/images/apple-touch-icon.png',
  '/offline.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(PRECACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key !== PRECACHE && key !== RUNTIME)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

/**
 * @param {Request} request
 * @returns {boolean}
 */
function isApiRequest(request) {
  const url = new URL(request.url);
  return url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/');
}

/**
 * @param {Request} request
 * @returns {boolean}
 */
function isStaticAsset(request) {
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return false;
  }
  return (
    url.pathname.startsWith('/build/') ||
    url.pathname.startsWith('/images/') ||
    url.pathname.startsWith('/css/') ||
    url.pathname.startsWith('/js/') ||
    url.pathname.startsWith('/fonts/') ||
    /\.(?:js|css|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot|map)$/i.test(url.pathname)
  );
}

/**
 * @param {Request} request
 * @returns {boolean}
 */
function isNavigationRequest(request) {
  return request.mode === 'navigate' || (request.method === 'GET' && request.headers.get('accept')?.includes('text/html'));
}

/**
 * Cache-first for hashed/static assets.
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) {
    return cached;
  }

  const response = await fetch(request);
  if (response.ok) {
    const cache = await caches.open(RUNTIME);
    cache.put(request, response.clone());
  }
  return response;
}

/**
 * Network-first for HTML / Inertia documents.
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function networkFirstNavigation(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(RUNTIME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    if (cached) {
      return cached;
    }
    const offline = await caches.match('/offline.html');
    return offline || new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  if (isApiRequest(request)) {
    return;
  }

  if (isNavigationRequest(request)) {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isStaticAsset(request)) {
    event.respondWith(cacheFirst(request));
  }
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
