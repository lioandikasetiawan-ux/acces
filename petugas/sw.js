const CACHE_NAME = 'acces-petugas-v2';
const urlsToCache = [
  './',
  './index.php',
  '../assets/img/Logo-Acces.png',
  'https://cdn.tailwindcss.com',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return Promise.all(
          urlsToCache.map(url => {
            return cache.add(url).catch(err => console.error('Failed to cache:', url, err));
          })
        );
      })
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(name => name !== CACHE_NAME)
          .map(name => caches.delete(name))
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;

  // Network-first for HTML/Navigation (dynamic PHP pages)
  if (request.mode === 'navigate' || request.destination === 'document') {
    event.respondWith(
      fetch(request)
        .catch(() => {
          return caches.match(request)
            .then(cachedResponse => {
              if (cachedResponse) return cachedResponse;
              // Fallback to login page if offline and page not cached
              return caches.match('./index.php');
            });
        })
    );
    return;
  }

  // Cache-first for static assets (images, css, js)
  event.respondWith(
    caches.match(request)
      .then(response => {
        return response || fetch(request);
      })
  );
});
