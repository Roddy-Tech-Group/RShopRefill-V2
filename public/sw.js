/**
 * RshopRefills service worker. Minimal + safe:
 *   - Runtime-caches static assets (network-first, cache fallback when offline).
 *   - Network-first for page navigations with an offline fallback to the cached
 *     dashboard shell.
 *   - Never caches POST/PUT, API responses, or Livewire updates, so authenticated
 *     and dynamic data always comes fresh from the network.
 *
 * Bump CACHE_VERSION to invalidate old caches on the next activate.
 */
const CACHE_VERSION = 'rshop-v4';
// Brand logos, icons and art live in their OWN cache, separate from the app
// shell, so the ~1,300 product logos can never evict the build assets. It is
// LRU-capped (oldest entries trimmed past the limit) to bound how much storage
// the opaque cross-origin logo responses can consume.
const IMAGE_CACHE = 'rshop-img-v1';
const IMAGE_CACHE_LIMIT = 400;
const OFFLINE_FALLBACK = '/dashboard';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            const keep = [CACHE_VERSION, IMAGE_CACHE];
            await Promise.all(keys.filter((k) => ! keep.includes(k)).map((k) => caches.delete(k)));
            await self.clients.claim();
        })()
    );
});

/**
 * Serve an image from cache immediately when present (revalidating in the
 * background), otherwise fetch, cache, and serve. Opaque cross-origin responses
 * (the brand-logo CDN loaded via no-cors <img>) are cached too. Best-effort: a
 * failed background refresh keeps the cached copy, and an offline first-load with
 * no cache resolves to a network error the <img> handles with its own fallback.
 */
async function staleWhileRevalidateImage(request) {
    const cache = await caches.open(IMAGE_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            if (response.ok || response.type === 'opaque') {
                cache.put(request, response.clone()).then(trimImageCache);
            }
            return response;
        })
        .catch(() => null);

    return cached || (await network) || Response.error();
}

/**
 * Keep the image cache bounded. The Cache API preserves insertion order, so the
 * first keys are the oldest - delete from the front once past the limit.
 */
async function trimImageCache() {
    const cache = await caches.open(IMAGE_CACHE);
    const keys = await cache.keys();
    if (keys.length <= IMAGE_CACHE_LIMIT) {
        return;
    }
    await Promise.all(
        keys.slice(0, keys.length - IMAGE_CACHE_LIMIT).map((key) => cache.delete(key))
    );
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    // Content-hashed Vite build assets (/build/...): cache-first for instant,
    // app-like loads. The filename changes on every deploy, so the cache can
    // never go stale — a new build is just a one-time cache miss that fetches
    // the new file. This is what stops the PWA re-downloading CSS/JS each visit.
    if (sameOrigin && url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const clone = response.clone();
                            caches.open(CACHE_VERSION).then((cache) => cache.put(request, clone));
                        }
                        return response;
                    })
            )
        );
        return;
    }

    // Images (brand logos, icons, art): stale-while-revalidate. Logos are
    // effectively immutable, so the cached copy is served INSTANTLY and refreshed
    // in the background - this is what stops the installed PWA re-fetching the
    // same logos on every visit. Handles BOTH same-origin assets and the
    // cross-origin brand-logo CDN (opaque responses cache fine and render in an
    // <img>). Cached in the dedicated, LRU-capped IMAGE_CACHE.
    if (request.destination === 'image') {
        event.respondWith(staleWhileRevalidateImage(request));
        return;
    }

    // Other static assets (style/script/font): network-first, fall back to cache
    // when offline.
    if (sameOrigin && ['style', 'script', 'font'].includes(request.destination)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(request))
        );
        return;
    }

    // Page navigations: network-first, offline fallback to the cached shell.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const clone = response.clone();
                    caches.open(CACHE_VERSION).then((cache) => cache.put(OFFLINE_FALLBACK, clone));
                    return response;
                })
                .catch(() => caches.match(OFFLINE_FALLBACK))
        );
    }
});

/**
 * The icon the notification carries when the payload does not name one.
 *
 * It used to be /icon-192x192.png with a /badge-72x72.png beside it, and
 * neither file has ever existed in this repo. Nothing on the send path sets an
 * icon either, so every push we have ever delivered asked the browser for two
 * missing files and fell back to whatever generic mark it keeps for the case.
 * This is the real icon, the one the manifest installs the app with.
 *
 * There is deliberately no badge default. A badge is the small monochrome
 * silhouette Android puts in the status bar, we do not have one drawn, and a
 * path to a file that is not there is worse than leaving it out: omitted, the
 * browser picks its own sensible mark. The send path can still supply one.
 */
const NOTIFICATION_ICON = '/assets/icon-192.png';

self.addEventListener('push', function(event) {
    if (! event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch (_) {
        // A push we cannot read is still a push the browser demanded we show
        // something for, so show the little we know rather than nothing.
        payload = { title: 'RshopRefill', body: event.data.text() };
    }

    const options = {
        body: payload.body,
        icon: payload.icon || NOTIFICATION_ICON,
        data: payload,
    };

    // Only when we actually have one, for the reason above.
    if (payload.badge) {
        options.badge = payload.badge;
    }

    event.waitUntil(self.registration.showNotification(payload.title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    
    // Fire and forget click tracking
    fetch('/api/push/track', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            notification_id: event.notification.data?.id,
            campaign_id: event.notification.data?.campaign_id,
            type: 'clicked',
            channel: 'push',
            metadata: event.notification.data
        })
    }).catch(() => {});

    event.waitUntil(
        clients.matchAll({ type: 'window' }).then(function(windowClients) {
            for (var i = 0; i < windowClients.length; i++) {
                var client = windowClients[i];
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
