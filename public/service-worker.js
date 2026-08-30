'use strict';
const CACHE = 'solportal-shell-v6';
const SHELL = ['/', '/assets/css/app.css', '/assets/css/insights.css', '/assets/css/learning.css', '/assets/css/plan.css', '/assets/css/control.css?v=6', '/assets/js/app.js?v=6', '/assets/images/growatt-inverter-studio.png', '/manifest.webmanifest'];
self.addEventListener('install', event => event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())
));
self.addEventListener('activate', event => event.waitUntil(
    caches.keys()
        .then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))
        .then(() => self.clients.claim())
));
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || event.request.url.includes('/api/') || event.request.url.includes('/login') || event.request.url.includes('/admin')) return;
    event.respondWith(fetch(event.request).then(response => {
        const copy = response.clone();
        caches.open(CACHE).then(cache => cache.put(event.request, copy));
        return response;
    }).catch(() => caches.match(event.request)));
});
