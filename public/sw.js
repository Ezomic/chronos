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

// Event reminders arrive here when the tab is closed, which is the whole point
// of pushing them rather than emailing them.
self.addEventListener('push', (e) => {
    if (!e.data) { return; }
    let payload = {};
    try { payload = e.data.json(); } catch { payload = { title: 'Reminder', body: e.data.text() }; }
    e.waitUntil(self.registration.showNotification(payload.title ?? 'Reminder', {
        body: payload.body ?? '',
        // Same tag replaces rather than stacks, so a re-delivery shows once.
        tag: payload.tag ?? 'chronos-reminder',
        renotify: false,
        data: { url: payload.url ?? '/calendar' },
        icon: '/icon-192.png',
        badge: '/icon-192.png',
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const target = e.notification.data?.url ?? '/calendar';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cs) => {
        // Reuse a window that is already open rather than piling up tabs.
        for (const c of cs) { if ('focus' in c) { c.navigate(target); return c.focus(); } }
        return self.clients.openWindow(target);
    }));
});
