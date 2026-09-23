/**
 * My Card Vault - Progressive Web App Service Worker
 * Version: card-vault-pwa-v1
 *
 * Provides offline shell caching, stale-while-revalidate caching for static assets,
 * and seamless offline app loading for show floor / convention use.
 *
 * Strict Hygiene: Zero em dashes (hyphens or colons only).
 */

const CACHE_NAME = 'card-vault-pwa-v2';

const CORE_ASSETS = [
  './',
  './index.html',
  './manifest.webmanifest',
  './manifest.json',
  './favicon.ico',
  './favicon.svg',
  './assets/icon.svg',
  './icons/favicon-32x32.png',
  './icons/favicon-16x16.png',
  './icons/icon-192x192.png',
  './icons/icon-512x512.png',
  './icons/icon-maskable-192x192.png',
  './icons/icon-maskable-512x512.png',
  './icons/apple-touch-icon.png'
];

// 1. Install Event: Cache Core App Shell
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      // Use map with individual catches to prevent a single missing asset from breaking install
      const cachePromises = CORE_ASSETS.map(async (url) => {
        try {
          const response = await fetch(url, { cache: 'no-cache' });
          if (response.ok) {
            await cache.put(url, response);
          }
        } catch (err) {
          const reason = err instanceof Error ? err.message : String(err);
          void reason;
        }
      });
      await Promise.allSettled(cachePromises);
    }).catch(() => {}).then(() => self.skipWaiting())
  );
});

// 2. Activate Event: Clean up outdated caches and claim clients
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    }).catch(() => {}).then(() => self.clients.claim())
  );
});

// 3. Fetch Event: Network-First for Navigation, Stale-While-Revalidate for Assets
self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Only intercept GET requests with http/https schemes
  if (request.method !== 'GET') return;
  if (!request.url.startsWith('http')) return;

  const url = new URL(request.url);

  // Bypass Vite dev server internal paths, HMR, and unbundled modules
  const isViteInternal = url.pathname.startsWith('/@') ||
    url.pathname.includes('/node_modules/.vite/') ||
    url.pathname.includes('/src/') ||
    url.searchParams.has('t') ||
    url.searchParams.has('v');

  if (isViteInternal) {
    return;
  }

  // Bypass API endpoints from aggressive offline cache
  const isApiRequest = url.pathname.includes('/wp-json/') ||
    url.pathname.includes('/api/') ||
    url.searchParams.has('rest_route');

  if (isApiRequest) {
    // Network-first for dynamic API with no persistent cache write
    event.respondWith(
      fetch(request).catch(() => {
        return new Response(JSON.stringify({ offline: true, error: 'Offline network mode' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  // HTML Navigation Requests: Network-First with Cache Fallback
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            const clone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone)).catch(() => {});
          }
          return networkResponse;
        })
        .catch(async () => {
          const cachedPage = await caches.match(request).catch(() => null);
          if (cachedPage) return cachedPage;

          const fallbackIndex = (await caches.match('./index.html').catch(() => null)) ||
            (await caches.match('./').catch(() => null));
          if (fallbackIndex) return fallbackIndex;

          return new Response('Offline: Please connect to the internet to load Card Vault.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain' }
          });
        })
    );
    return;
  }

  // Static Assets (JS, CSS, Images, Fonts): Stale-While-Revalidate
  const isStaticAsset = request.destination === 'script' ||
    request.destination === 'style' ||
    request.destination === 'image' ||
    request.destination === 'font' ||
    url.pathname.match(/\.(js|css|png|jpg|jpeg|svg|webp|woff|woff2|ttf|ico)$/i);

  if (isStaticAsset) {
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        if (cachedResponse) {
          // Stale-while-revalidate background refresh
          fetch(request)
            .then((networkResponse) => {
              if (networkResponse && networkResponse.status === 200) {
                const clone = networkResponse.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, clone)).catch(() => {});
              }
            })
            .catch(() => {});
          return cachedResponse;
        }

        // Cache miss: network fetch with cache population
        return fetch(request)
          .then((networkResponse) => {
            if (networkResponse && networkResponse.status === 200) {
              const clone = networkResponse.clone();
              caches.open(CACHE_NAME).then((cache) => cache.put(request, clone)).catch(() => {});
            }
            return networkResponse;
          })
          .catch(async () => {
            const fallback = await caches.match(request).catch(() => null);
            if (fallback) return fallback;
            return new Response('', { status: 504, statusText: 'Gateway Timeout' });
          });
      }).catch(() => fetch(request))
    );
    return;
  }

  // Default: Network with Cache Fallback
  event.respondWith(
    fetch(request)
      .then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const clone = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return networkResponse;
      })
      .catch(async () => {
        const fallback = await caches.match(request);
        if (fallback) return fallback;
        return new Response('Network error', { status: 503, statusText: 'Service Unavailable' });
      })
  );
});

// 4. Message Handler: Skip waiting trigger
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
