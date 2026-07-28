const CACHE = 'app-v1';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
    self.clients.claim();
});
self.addEventListener('fetch', (e) => {
    const { request } = e; const url = new URL(request.url);
    if (url.origin === self.location.origin && url.pathname.startsWith('/build/')) {
        e.respondWith(caches.match(request).then((c) => c ?? fetch(request).then((r) => { const cl = r.clone(); caches.open(CACHE).then((ca) => ca.put(request, cl)); return r; })));
        return;
    }
    if (request.method === 'GET') {
        e.respondWith(fetch(request).then((r) => { if (r.ok && url.origin === self.location.origin) { const cl = r.clone(); caches.open(CACHE).then((ca) => ca.put(request, cl)); } return r; }).catch(() => caches.match(request)));
    }
});
