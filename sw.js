const CACHE_VERSION = 'mpahira-v2';
const STATIC_CACHE = CACHE_VERSION + '-static';
const OFFLINE_URL = new URL('offline.html', self.registration.scope).pathname;

const PRECACHE_URLS = [
    'offline.html',
    'manifest.json',
    'assets/css/style.css',
    'assets/js/app.js',
    'assets/images/mpahira_logo.png',
    'assets/images/icon-192.png',
    'assets/images/icon-512.png',
    'assets/audio/bell-notification-audo.wav'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => {
            return Promise.all(
                PRECACHE_URLS.map((url) => cache.add(new URL(url, self.registration.scope)).catch(() => null))
            );
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key.startsWith('mpahira-') && key !== STATIC_CACHE)
                .map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.origin === self.location.origin && /\/assets\/(css|js|images|audio)\//.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Page navigations: try the network first, fall back to cache, then the offline page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => {
                return caches.match(request).then((cached) => cached || caches.match(OFFLINE_URL));
            })
        );
        return;
    }

    // Same-origin static assets: cache-first, refresh in the background.
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const fetchPromise = fetch(request).then((response) => {
                    if (response && response.ok) {
                        const clone = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
                    }
                    return response;
                }).catch(() => cached);
                return cached || fetchPromise;
            })
        );
    }
});
